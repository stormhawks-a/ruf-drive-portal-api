<?php

/**
 * Chunked/resumable upload for very large files (100MB+, up to tens of GB).
 * The browser slices the file into fixed-size chunks and PUTs them one at a
 * time; this server never buffers more than one chunk in memory and forwards
 * each straight through to a Google Drive resumable upload session. If a
 * chunk PUT fails partway through (dropped connection etc.), the client
 * re-queries /file-uploads/{id} to find out how many bytes Drive actually has
 * and resumes from there instead of restarting the whole file.
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

    Response::json(['uploadId' => $id], 201);
}

function file_uploads_chunk(array $params): void
{
    // Must allow at least as long as GoogleDriveClient::uploadChunk's own 1800s
    // curl timeout — otherwise PHP would kill the request first regardless.
    @set_time_limit(1800);
    $user = Auth::requireAuth();
    $id = $params['id'];
    $upload = Db::queryOne('SELECT * FROM file_uploads WHERE id = ?', [$id]);
    if ($upload === null) {
        Response::error('Yükleme oturumu bulunamadı.', 404);
    }
    if ($upload['owner_id'] !== $user['id']) {
        Response::error('Yetkiniz yok.', 403);
    }
    if ($upload['status'] !== 'uploading') {
        Response::json(['done' => $upload['status'] === 'completed', 'bytesReceived' => (int) $upload['bytes_received']]);
        return;
    }

    $start = isset($_SERVER['HTTP_X_CHUNK_START']) ? (int) $_SERVER['HTTP_X_CHUNK_START'] : -1;
    if ($start < 0) {
        Response::error('X-Chunk-Start başlığı zorunlu.', 422);
    }

    // Already applied — client is retrying after a response it never saw. Just
    // report current state instead of sending these bytes to Drive a second time.
    if ($start < (int) $upload['bytes_received']) {
        Response::json(['done' => false, 'bytesReceived' => (int) $upload['bytes_received']]);
        return;
    }
    if ($start > (int) $upload['bytes_received']) {
        Response::error('Beklenmeyen parça sırası, yükleme durumunu tekrar sorgulayın.', 409);
    }

    $chunkData = file_get_contents('php://input');
    if ($chunkData === false || $chunkData === '') {
        Response::error('Parça verisi boş.', 422);
    }

    try {
        $result = GoogleDriveClient::uploadChunk($upload['drive_session_uri'], $chunkData, $start, (int) $upload['total_bytes']);
    } catch (Throwable $e) {
        error_log('Buyuk dosya parca yuklemesi basarisiz: ' . $e->getMessage());
        Response::error('Parça yüklenemedi, tekrar deneyin.', 502);
    }
    unset($chunkData);

    Db::execute('UPDATE file_uploads SET bytes_received = ? WHERE id = ?', [$result['bytesReceived'], $id]);

    if (!$result['done']) {
        Response::json(['done' => false, 'bytesReceived' => $result['bytesReceived']]);
        return;
    }

    $fileId = Ids::generate('file');
    Db::execute(
        'INSERT INTO files (id, name, original_name, size_bytes, mime_type, file_type, parent_id, owner_id, drive_file_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $fileId, $upload['name'], $upload['name'], $upload['total_bytes'], $upload['mime_type'],
            files_infer_type($upload['name'], (string) $upload['mime_type']), $upload['parent_id'], $user['id'], $result['fileId'],
        ]
    );
    Db::execute('UPDATE file_uploads SET status = ?, drive_file_id = ? WHERE id = ?', ['completed', $result['fileId'], $id]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_UPLOAD', "Dosya eklendi: {$upload['name']}");

    $row = Db::queryOne('SELECT * FROM files WHERE id = ?', [$fileId]);
    Response::json(['done' => true, 'bytesReceived' => $result['bytesReceived'], 'file' => $row]);
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

    Response::json(['status' => $upload['status'], 'bytesReceived' => $bytesReceived, 'file' => $file]);
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
    ['PUT', '#^/file-uploads/(?P<id>[a-zA-Z0-9_]+)/chunk$#', 'file_uploads_chunk'],
    ['GET', '#^/file-uploads/(?P<id>[a-zA-Z0-9_]+)$#', 'file_uploads_status'],
    ['DELETE', '#^/file-uploads/(?P<id>[a-zA-Z0-9_]+)$#', 'file_uploads_cancel'],
];
