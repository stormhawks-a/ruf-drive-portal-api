<?php

/**
 * Chunked/resumable upload for very large files (100MB+, up to tens of GB).
 * The browser slices the file into fixed-size chunks, but — unlike a plain
 * relay — PUTs each one straight to a Cloudflare Worker (cloudflare-worker/),
 * not to this server, because this hosting account's inbound bandwidth is
 * hard-capped (~1.8-1.9MB/s, confirmed by Natro support as a fixed shared-
 * hosting limit) while browser<->Drive and server<->Drive are both an order of
 * magnitude faster. This file only ever handles the small control-plane calls
 * (start a Drive resumable session, check status, finalize) — never the bulk
 * bytes themselves. `file_uploads_start` mints a signed ticket (see
 * ChunkRelayTicket) authorizing the Worker to relay chunks into exactly one
 * Drive resumable session; `file_uploads_finalize` independently re-verifies
 * with Drive itself (never trusting the client's say-so) before writing the
 * `files` row.
 */

function file_uploads_start(array $params): void
{
    $user = Auth::requireAuth();
    $body = Response::body();
    $parentId = trim((string) ($body['parentId'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $mimeType = (string) ($body['mimeType'] ?? 'application/octet-stream');
    $totalBytes = (int) ($body['totalBytes'] ?? 0);

    if ($parentId === '' || $name === '' || $totalBytes <= 0) {
        Response::error('parentId, name ve totalBytes zorunlu.', 422);
    }
    Scope::assertFolderAccessible($user, $parentId);

    $folder = Db::queryOne('SELECT drive_folder_id FROM folders WHERE id = ?', [$parentId]);
    $driveParentId = $folder['drive_folder_id'] ?? null;
    if ($driveParentId === null) {
        Response::error('Klasörün Drive bağlantısı yok.', 502);
    }

    $sessionUri = GoogleDriveClient::createResumableSession($name, $driveParentId, $mimeType, $totalBytes);

    $id = Ids::generate('upl');
    Db::execute(
        'INSERT INTO file_uploads (id, parent_id, owner_id, name, mime_type, total_bytes, drive_session_uri) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$id, $parentId, $user['id'], $name, $mimeType, $totalBytes, $sessionUri]
    );

    $ticket = ChunkRelayTicket::mint($id, $sessionUri, $totalBytes);
    Response::json(['uploadId' => $id, 'ticket' => $ticket], 201);
}

/**
 * Called by the browser once the Worker reports every chunk delivered. Never
 * trusts $driveFileId at face value — re-fetches it from Drive itself and
 * checks size + parent folder before writing the `files` row, because the
 * Worker (and therefore whatever the browser forwards from it) is outside our
 * own auth boundary. Idempotent: safe to call twice for the same upload.
 */
function file_uploads_finalize(array $params): void
{
    $user = Auth::requireAuth();
    $id = $params['id'];
    $upload = Db::queryOne('SELECT * FROM file_uploads WHERE id = ?', [$id]);
    if ($upload === null) {
        Response::error('Yükleme oturumu bulunamadı.', 404);
    }
    if ($upload['owner_id'] !== $user['id']) {
        Response::error('Yetkiniz yok.', 403);
    }

    if ($upload['status'] === 'completed') {
        $existing = Db::queryOne('SELECT * FROM files WHERE drive_file_id = ?', [$upload['drive_file_id']]);
        if ($existing !== null) {
            Response::json(['file' => $existing]);
            return;
        }
    }

    $body = Response::body();
    $driveFileId = trim((string) ($body['driveFileId'] ?? ''));
    if ($driveFileId === '') {
        Response::error('driveFileId zorunlu.', 422);
    }
    if ($upload['drive_file_id'] !== null && $upload['drive_file_id'] !== $driveFileId) {
        Response::error('Bu yükleme oturumu zaten farklı bir dosyayla tamamlanmış.', 409);
    }

    $folder = Db::queryOne('SELECT drive_folder_id FROM folders WHERE id = ?', [$upload['parent_id']]);
    $driveParentId = $folder['drive_folder_id'] ?? null;

    try {
        $meta = GoogleDriveClient::getFileMeta($driveFileId, 'id,size,parents');
    } catch (Throwable $e) {
        error_log('Buyuk dosya finalize dogrulamasi basarisiz: ' . $e->getMessage());
        Response::error('Yükleme Drive üzerinde doğrulanamadı, henüz tamamlanmamış olabilir.', 502);
    }

    if ((int) ($meta['size'] ?? -1) !== (int) $upload['total_bytes']) {
        Response::error('Yüklenen dosyanın boyutu beklenenle eşleşmiyor.', 409);
    }
    if ($driveParentId !== null && !in_array($driveParentId, $meta['parents'] ?? [], true)) {
        Response::error('Yüklenen dosya beklenen klasörde değil.', 409);
    }

    $fileId = Ids::generate('file');
    Db::execute(
        'INSERT INTO files (id, name, original_name, size_bytes, mime_type, file_type, parent_id, owner_id, drive_file_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $fileId, $upload['name'], $upload['name'], $upload['total_bytes'], $upload['mime_type'],
            files_infer_type($upload['name'], (string) $upload['mime_type']), $upload['parent_id'], $user['id'], $driveFileId,
        ]
    );
    Db::execute('UPDATE file_uploads SET status = ?, drive_file_id = ?, bytes_received = total_bytes WHERE id = ?', ['completed', $driveFileId, $id]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_UPLOAD', "Dosya eklendi: {$upload['name']}");

    $row = Db::queryOne('SELECT * FROM files WHERE id = ?', [$fileId]);
    Response::json(['file' => $row]);
}

function file_uploads_status(array $params): void
{
    $user = Auth::requireAuth();
    $id = $params['id'];
    $upload = Db::queryOne('SELECT * FROM file_uploads WHERE id = ?', [$id]);
    if ($upload === null) {
        Response::error('Yükleme oturumu bulunamadı.', 404);
    }
    if ($upload['owner_id'] !== $user['id']) {
        Response::error('Yetkiniz yok.', 403);
    }

    $bytesReceived = (int) $upload['bytes_received'];
    if ($upload['status'] === 'uploading') {
        try {
            $bytesReceived = GoogleDriveClient::queryResumableProgress($upload['drive_session_uri'], (int) $upload['total_bytes']);
            Db::execute('UPDATE file_uploads SET bytes_received = ? WHERE id = ?', [$bytesReceived, $id]);
        } catch (Throwable $e) {
            error_log('Yukleme durumu sorgulanamadi: ' . $e->getMessage());
        }
    }

    // If the client lost the response that announced completion (e.g. connection
    // dropped right on the last chunk), it still needs the finished file's row —
    // this is the only other place it can recover that from.
    $file = null;
    if ($upload['status'] === 'completed' && $upload['drive_file_id'] !== null) {
        $file = Db::queryOne('SELECT * FROM files WHERE drive_file_id = ?', [$upload['drive_file_id']]);
    }

    // A fresh ticket every time this is polled — the original one from
    // file_uploads_start has a long but finite lifetime (see ChunkRelayTicket),
    // and a resumed multi-day upload of a very large file could otherwise stall
    // once it expires with no way for the browser to get a new one.
    $ticket = $upload['status'] === 'uploading'
        ? ChunkRelayTicket::mint($id, $upload['drive_session_uri'], (int) $upload['total_bytes'])
        : null;

    Response::json(['status' => $upload['status'], 'bytesReceived' => $bytesReceived, 'file' => $file, 'ticket' => $ticket]);
}

function file_uploads_cancel(array $params): void
{
    $user = Auth::requireAuth();
    $id = $params['id'];
    $upload = Db::queryOne('SELECT * FROM file_uploads WHERE id = ?', [$id]);
    if ($upload === null) {
        Response::error('Yükleme oturumu bulunamadı.', 404);
    }
    if ($upload['owner_id'] !== $user['id']) {
        Response::error('Yetkiniz yok.', 403);
    }
    Db::execute('DELETE FROM file_uploads WHERE id = ?', [$id]);
    Response::json(['ok' => true]);
}

return [
    ['POST', '#^/file-uploads$#', 'file_uploads_start'],
    ['POST', '#^/file-uploads/(?P<id>[a-zA-Z0-9_]+)/finalize$#', 'file_uploads_finalize'],
    ['GET', '#^/file-uploads/(?P<id>[a-zA-Z0-9_]+)$#', 'file_uploads_status'],
    ['DELETE', '#^/file-uploads/(?P<id>[a-zA-Z0-9_]+)$#', 'file_uploads_cancel'],
];
