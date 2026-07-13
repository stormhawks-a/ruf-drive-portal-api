<?php

function files_infer_type(string $name, string $mimeType): string
{
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'pdf' => 'pdf',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image', 'svg' => 'image',
        'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'webm' => 'video', 'mkv' => 'video',
        'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'm4a' => 'audio',
        'doc' => 'doc', 'docx' => 'doc', 'txt' => 'doc', 'rtf' => 'doc',
        'xls' => 'sheet', 'xlsx' => 'sheet', 'csv' => 'sheet',
    ];
    if (isset($map[$ext])) {
        return $map[$ext];
    }
    if (str_starts_with($mimeType, 'image/')) return 'image';
    if (str_starts_with($mimeType, 'video/')) return 'video';
    if (str_starts_with($mimeType, 'audio/')) return 'audio';
    return 'other';
}

function files_list(array $params): void
{
    $user = Auth::requireAuth();

    if (isset($_GET['all'])) {
        $rows = Db::query('SELECT * FROM files ORDER BY created_at ASC');
        if ($user['role'] === 'CUSTOMER') {
            $rootId = $user['folder_id'];
            $rows = array_values(array_filter($rows, function ($f) use ($rootId) {
                return Scope::isWithinCustomerRoot($f['parent_id'], $rootId);
            }));
        }
        Response::json(['files' => $rows]);
        return;
    }

    $parentId = $_GET['parentId'] ?? null;

    if ($parentId === null) {
        Response::error('parentId zorunlu.', 422);
    }
    Scope::assertFolderAccessible($user, $parentId);

    $rows = Db::query('SELECT * FROM files WHERE parent_id = ? AND deleted_at IS NULL ORDER BY created_at ASC', [$parentId]);
    Response::json(['files' => $rows]);
}

function files_create(array $params): void
{
    $user = Auth::requireAuth();

    $parentId = $_POST['parentId'] ?? null;
    if ($parentId === null || $parentId === '') {
        Response::error('Klasör zorunlu.', 422);
    }
    Scope::assertFolderAccessible($user, $parentId);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        Response::error('Dosya yüklenemedi.', 422);
    }
    $uploaded = $_FILES['file'];

    $name = trim((string) ($_POST['name'] ?? $uploaded['name']));
    if ($name === '') {
        Response::error('Dosya adı zorunlu.', 422);
    }
    $mimeType = (string) ($uploaded['type'] ?: 'application/octet-stream');
    $sizeBytes = (int) $uploaded['size'];
    $fileType = files_infer_type($name, $mimeType);

    $id = Ids::generate('file');
    $driveFileId = null;
    try {
        $driveParent = Db::queryOne('SELECT drive_folder_id FROM folders WHERE id = ?', [$parentId]);
        $driveParentId = $driveParent['drive_folder_id'] ?? null;
        if ($driveParentId !== null) {
            $contents = file_get_contents($uploaded['tmp_name']);
            $driveFileId = GoogleDriveClient::uploadFile($name, $driveParentId, $mimeType, $contents);
        }
    } catch (Throwable $e) {
        error_log('Drive dosya yukleme basarisiz: ' . $e->getMessage());
    }

    Db::execute(
        'INSERT INTO files (id, name, original_name, size_bytes, mime_type, file_type, parent_id, owner_id, drive_file_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $name, $uploaded['name'], $sizeBytes, $mimeType, $fileType, $parentId, $user['id'], $driveFileId]
    );
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_UPLOAD', "Dosya eklendi: {$name}");

    $row = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    Response::json(['file' => $row], 201);
}

function files_download(array $params): void
{
    $id = $params['id'];
    $file = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    if ($file === null) {
        Response::error('Dosya bulunamadı.', 404);
    }

    // Two ways in: a real logged-in account scoped to this file's folder, or an
    // unlocked share-link session whose shared scope includes this file.
    $user = Auth::currentUser();
    if ($user !== null) {
        Scope::assertFolderAccessible($user, $file['parent_id']);
        AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_DOWNLOAD', "Dosya indirildi: {$file['name']}");
    } else {
        $shareLinkId = Auth::currentShareLinkId();
        if ($shareLinkId === null || !shared_links_grants_file($shareLinkId, $file)) {
            Response::error('Oturum açmanız gerekiyor.', 401);
        }
        Db::execute('UPDATE shared_links SET download_count = download_count + 1 WHERE id = ?', [$shareLinkId]);
    }

    if ($file['drive_file_id'] === null) {
        Response::error('Bu dosya için depolanmış bir kopya yok.', 404);
    }

    // A many-GB download can easily take longer than PHP's default execution-time
    // cap on a normal connection — without this it gets killed mid-stream.
    @set_time_limit(0);

    $totalSize = (int) $file['size_bytes'];
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['name']) . '"');

    // Honor Range requests so a dropped connection resumes the rest of a huge file
    // instead of restarting it from byte zero — this is what makes browsers'
    // built-in "resume download" work at all; without Accept-Ranges/206 support
    // they silently fall back to downloading everything again from scratch.
    $range = files_parse_range_header($_SERVER['HTTP_RANGE'] ?? null, $totalSize);
    if ($range !== null) {
        [$start, $end] = $range;
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$totalSize}");
        header('Content-Length: ' . ($end - $start + 1));
        GoogleDriveClient::streamFileTo($file['drive_file_id'], function (string $chunk): void {
            echo $chunk;
        }, "bytes={$start}-{$end}");
        exit;
    }

    if ($totalSize > 0) {
        header('Content-Length: ' . $totalSize);
    }
    GoogleDriveClient::streamFile($file['drive_file_id']);
    exit;
}

/** Parses a "Range: bytes=start-end" (or open-ended "start-" / suffix "-N") header
    into a validated [start, end] pair, or null if absent/unsatisfiable — in which
    case the caller should just serve the whole file instead of erroring out. */
function files_parse_range_header(?string $rangeHeader, int $totalSize): ?array
{
    if ($rangeHeader === null || $totalSize <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m)) {
        return null;
    }
    $reqStart = $m[1] === '' ? null : (int) $m[1];
    $reqEnd = $m[2] === '' ? null : (int) $m[2];

    if ($reqStart === null && $reqEnd === null) {
        return null;
    }
    if ($reqStart === null) {
        // Suffix form ("-500" = last 500 bytes).
        $start = max(0, $totalSize - $reqEnd);
        $end = $totalSize - 1;
    } else {
        $start = $reqStart;
        $end = $reqEnd !== null ? min($reqEnd, $totalSize - 1) : $totalSize - 1;
    }
    if ($start < 0 || $start > $end || $start >= $totalSize) {
        return null;
    }
    return [$start, $end];
}

/** Same access rules as files_download, but serves a small pre-generated Drive
    thumbnail instead of the original bytes — only ever used for jpg/png previews,
    so large source photos don't have to be fully downloaded just to browse them. */
function files_thumbnail(array $params): void
{
    $id = $params['id'];
    $file = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    if ($file === null) {
        Response::error('Dosya bulunamadı.', 404);
    }

    $user = Auth::currentUser();
    if ($user !== null) {
        Scope::assertFolderAccessible($user, $file['parent_id']);
    } else {
        $shareLinkId = Auth::currentShareLinkId();
        if ($shareLinkId === null || !shared_links_grants_file($shareLinkId, $file)) {
            Response::error('Oturum açmanız gerekiyor.', 401);
        }
    }

    if ($file['drive_file_id'] === null || !in_array($file['mime_type'], ['image/jpeg', 'image/png'], true)) {
        Response::error('Küçük resim mevcut değil.', 404);
    }

    $size = isset($_GET['size']) ? max(64, min(2048, (int) $_GET['size'])) : 480;
    if (!GoogleDriveClient::streamThumbnail($file['drive_file_id'], $size)) {
        Response::error('Küçük resim alınamadı.', 404);
    }
    exit;
}

function files_update(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $file = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    if ($file === null) {
        Response::error('Dosya bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $file['parent_id']);

    $body = Response::body();
    if (array_key_exists('name', $body)) {
        $name = trim((string) $body['name']);
        if ($name === '') {
            Response::error('Dosya adı boş olamaz.', 422);
        }
        Db::execute('UPDATE files SET name = ? WHERE id = ?', [$name, $id]);
        AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_RENAME', "Dosya yeniden adlandırıldı: {$file['name']} -> {$name}");
    }
    Response::json(['ok' => true]);
}

function files_delete(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $file = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    if ($file === null) {
        Response::error('Dosya bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $file['parent_id']);

    Db::execute('UPDATE files SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$user['id'], $id]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_DELETE', "Dosya çöp kutusuna taşındı: {$file['name']}");
    Response::json(['ok' => true]);
}

function files_restore(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $file = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    if ($file === null) {
        Response::error('Dosya bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $file['parent_id']);
    Db::execute('UPDATE files SET deleted_at = NULL, deleted_by = NULL WHERE id = ?', [$id]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_RESTORE', "Dosya çöp kutusundan geri yüklendi: {$file['name']}");
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/files$#', 'files_list'],
    ['POST', '#^/files$#', 'files_create'],
    ['GET', '#^/files/(?P<id>[a-zA-Z0-9_]+)/download$#', 'files_download'],
    ['GET', '#^/files/(?P<id>[a-zA-Z0-9_]+)/thumbnail$#', 'files_thumbnail'],
    ['PUT', '#^/files/(?P<id>[a-zA-Z0-9_]+)$#', 'files_update'],
    ['DELETE', '#^/files/(?P<id>[a-zA-Z0-9_]+)$#', 'files_delete'],
    ['POST', '#^/files/(?P<id>[a-zA-Z0-9_]+)/restore$#', 'files_restore'],
];
