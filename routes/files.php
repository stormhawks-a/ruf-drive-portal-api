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

    // A resumed/seeked transfer re-hits this same endpoint with a Range header
    // that picks up mid-file — only a request starting at byte 0 (or with no
    // Range at all) is a genuinely NEW download; counting every continuation
    // would wildly inflate the download-count stats below for any large file.
    $rangeForCounting = files_parse_range_header($_SERVER['HTTP_RANGE'] ?? null, (int) $file['size_bytes']);
    $isContinuation = $rangeForCounting !== null && $rangeForCounting[0] > 0;

    // The browser's native "download as real folder structure" path (File System
    // Access API) fetches every file inside a selected folder through this very
    // endpoint, one request per file — those pass ?skipCount=1 so a folder with
    // 50 files doesn't turn into 50 rows on the download-stats leaderboard; the
    // frontend instead registers ONE download against the folder itself (see
    // folders_register_download). A file the user picked individually never
    // sets this flag, so it still counts on its own as before.
    $skipCount = isset($_GET['skipCount']) && $_GET['skipCount'] === '1';

    // The PDF preview modal (PreviewModal.tsx) streams the file inline through
    // this exact same endpoint with ?inline=1 so it can render in an <iframe>
    // instead of prompting a save dialog — that's not a download the user
    // asked for, just a peek, so it must never log FILE_DOWNLOAD or bump
    // either download counter. Only the real download link (no ?inline) does.
    $isInlinePreview = isset($_GET['inline']) && $_GET['inline'] === '1';

    // Two ways in: a real logged-in account scoped to this file's folder, or an
    // unlocked share-link session whose shared scope includes this file.
    $user = Auth::currentUser();
    if ($user !== null) {
        Scope::assertFolderAccessible($user, $file['parent_id']);
        if (!$isContinuation && !$isInlinePreview) {
            AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_DOWNLOAD', "Dosya indirildi: {$file['name']}");
            if (!$skipCount) {
                Db::execute('UPDATE files SET download_count = download_count + 1 WHERE id = ?', [$id]);
            }
        }
    } else {
        $shareLinkId = Auth::currentShareLinkId();
        if ($shareLinkId === null || !shared_links_grants_file($shareLinkId, $file)) {
            Response::error('Oturum açmanız gerekiyor.', 401);
        }
        if (!$isContinuation && !$isInlinePreview) {
            Db::execute('UPDATE shared_links SET download_count = download_count + 1 WHERE id = ?', [$shareLinkId]);
            if (!$skipCount) {
                Db::execute('UPDATE files SET download_count = download_count + 1 WHERE id = ?', [$id]);
            }
        }
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
    // "attachment" makes a browser prompt/save the file for a real download — but
    // that's also what makes an <iframe> pointed at this same URL trigger a
    // download instead of actually rendering the PDF inline. The preview modal
    // requests ?inline=1 for exactly that reason; a real download link never
    // sets it, so normal downloads are unaffected.
    $disposition = (isset($_GET['inline']) && $_GET['inline'] === '1') ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $file['name']) . '"');

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

    if (array_key_exists('parentId', $body)) {
        $newParentId = $body['parentId'];
        if ($newParentId !== $file['parent_id']) {
            if ($newParentId === null) {
                Response::error('Hedef klasör bulunamadı.', 404);
            }
            $newParent = Db::queryOne('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL', [$newParentId]);
            if ($newParent === null) {
                Response::error('Hedef klasör bulunamadı.', 404);
            }
            Scope::assertFolderAccessible($user, $newParentId);

            $oldParentId = $file['parent_id'];
            Db::execute('UPDATE files SET parent_id = ? WHERE id = ?', [$newParentId, $id]);
            AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_MOVE', "Dosya taşındı: {$file['name']}");

            try {
                if ($file['drive_file_id'] !== null) {
                    $oldDriveParentId = folders_resolve_drive_parent($oldParentId);
                    $newDriveParentId = folders_resolve_drive_parent($newParentId);
                    if ($newDriveParentId !== null) {
                        GoogleDriveClient::moveFile($file['drive_file_id'], $oldDriveParentId, $newDriveParentId);
                    }
                }
            } catch (Throwable $e) {
                error_log('Drive dosya tasima basarisiz: ' . $e->getMessage());
            }
        }
    }

    Response::json(['ok' => true]);
}

/** Duplicates a file into another folder (same or a different customer) — a real
    Drive-side copy (GoogleDriveClient::copyFile), not a second DB row pointing at
    the same drive_file_id, so deleting either copy later can never touch the
    other one. Lets the same file be handed to two customers without uploading
    the bytes twice. */
function files_copy(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $file = Db::queryOne('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($file === null) {
        Response::error('Dosya bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $file['parent_id']);

    $body = Response::body();
    $destParentId = $body['parentId'] ?? null;
    if ($destParentId === null) {
        Response::error('Hedef klasör zorunlu.', 422);
    }
    $destFolder = Db::queryOne('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL', [$destParentId]);
    if ($destFolder === null) {
        Response::error('Hedef klasör bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $destParentId);

    $newId = Ids::generate('file');
    $newDriveFileId = null;
    if ($file['drive_file_id'] !== null && $destFolder['drive_folder_id'] !== null) {
        try {
            $newDriveFileId = GoogleDriveClient::copyFile($file['drive_file_id'], $file['name'], $destFolder['drive_folder_id']);
        } catch (Throwable $e) {
            error_log('Drive dosya kopyalama basarisiz: ' . $e->getMessage());
        }
    }

    Db::execute(
        'INSERT INTO files (id, name, original_name, size_bytes, mime_type, file_type, parent_id, owner_id, drive_file_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$newId, $file['name'], $file['original_name'], $file['size_bytes'], $file['mime_type'], $file['file_type'], $destParentId, $user['id'], $newDriveFileId]
    );
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_COPY', "Dosya kopyalandı: {$file['name']}");

    $row = Db::queryOne('SELECT * FROM files WHERE id = ?', [$newId]);
    Response::json(['file' => $row], 201);
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

    // Staff browsing into a customer's own folder tree (via the admin dashboard,
    // not a share link) still gets attributed to that customer in deleted_by —
    // see Scope::resolveOwningCustomerId's own docblock. A real customer deleting
    // their own file, or staff deleting something outside any customer's tree,
    // is unaffected (owningCustomerId is null there, or equals $user['id']).
    $deletedById = $user['id'];
    if ($user['role'] !== 'CUSTOMER') {
        $owningCustomerId = Scope::resolveOwningCustomerId($file['parent_id']);
        if ($owningCustomerId !== null) {
            $deletedById = $owningCustomerId;
        }
    }

    Db::execute('UPDATE files SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$deletedById, $id]);
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

/** Admin-only download leaderboard — most-downloaded item first, each with its
    full folder path so two same-named items in different folders aren't
    ambiguous in the list. A downloaded FOLDER is its own single row (however
    many files it contains) — it never explodes into one row per file inside
    it, since files.download_count is only bumped for individually-picked
    files, not ones swept up as part of a folder download (see zip.php). */
function files_download_stats(array $params): void
{
    Auth::requireRole('ADMIN');

    $fileRows = Db::query(
        'SELECT id, name, parent_id, download_count FROM files WHERE deleted_at IS NULL AND download_count > 0'
    );
    $downloadedFolderRows = Db::query(
        'SELECT id, name, parent_id, download_count FROM folders WHERE deleted_at IS NULL AND download_count > 0'
    );

    $allFolderRows = Db::query('SELECT id, name, parent_id FROM folders');
    $foldersById = [];
    foreach ($allFolderRows as $folder) {
        $foldersById[$folder['id']] = $folder;
    }

    $buildFolderPath = function (?string $parentId) use (&$buildFolderPath, $foldersById): string {
        if ($parentId === null || !isset($foldersById[$parentId])) {
            return '';
        }
        $folder = $foldersById[$parentId];
        $prefix = $buildFolderPath($folder['parent_id']);
        return $prefix === '' ? $folder['name'] : "{$prefix} / {$folder['name']}";
    };

    $result = [];
    foreach ($fileRows as $row) {
        $folderPath = $buildFolderPath($row['parent_id']);
        $result[] = [
            'id' => $row['id'],
            'type' => 'file',
            'name' => $row['name'],
            'downloadCount' => (int) $row['download_count'],
            'path' => $folderPath === '' ? $row['name'] : "{$folderPath} / {$row['name']}",
        ];
    }
    foreach ($downloadedFolderRows as $row) {
        $ancestorPath = $buildFolderPath($row['parent_id']);
        $result[] = [
            'id' => $row['id'],
            'type' => 'folder',
            'name' => $row['name'],
            'downloadCount' => (int) $row['download_count'],
            'path' => $ancestorPath === '' ? $row['name'] : "{$ancestorPath} / {$row['name']}",
        ];
    }

    usort($result, fn (array $a, array $b): int => $b['downloadCount'] <=> $a['downloadCount']);

    Response::json(['files' => $result]);
}

/** Zeroes every file's and folder's download_count — the frontend gates this
    behind its own confirmation prompt, same as the audit-log clear button. */
function files_download_stats_reset(array $params): void
{
    $actor = Auth::requireRole('ADMIN');
    Db::execute('UPDATE files SET download_count = 0');
    Db::execute('UPDATE folders SET download_count = 0');
    AuditLogger::log($actor['id'], $actor['name'], $actor['role'], 'PERMISSION_CHANGE', 'İndirme istatistikleri sıfırlandı.');
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/files$#', 'files_list'],
    ['POST', '#^/files$#', 'files_create'],
    ['GET', '#^/files/download-stats$#', 'files_download_stats'],
    ['DELETE', '#^/files/download-stats$#', 'files_download_stats_reset'],
    ['GET', '#^/files/(?P<id>[a-zA-Z0-9_]+)/download$#', 'files_download'],
    ['GET', '#^/files/(?P<id>[a-zA-Z0-9_]+)/thumbnail$#', 'files_thumbnail'],
    ['PUT', '#^/files/(?P<id>[a-zA-Z0-9_]+)$#', 'files_update'],
    ['DELETE', '#^/files/(?P<id>[a-zA-Z0-9_]+)$#', 'files_delete'],
    ['POST', '#^/files/(?P<id>[a-zA-Z0-9_]+)/restore$#', 'files_restore'],
    ['POST', '#^/files/(?P<id>[a-zA-Z0-9_]+)/copy$#', 'files_copy'],
];
