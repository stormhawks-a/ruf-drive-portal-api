<?php

/** A folder is downloadable if it's directly shared, or a descendant of a directly-shared one. */
function zip_folder_in_share_scope(string $shareLinkId, string $folderId): bool
{
    $direct = Db::queryOne('SELECT 1 FROM shared_link_folders WHERE shared_link_id = ? AND folder_id = ?', [$shareLinkId, $folderId]);
    if ($direct !== null) {
        return true;
    }
    $rootIds = array_column(Db::query('SELECT folder_id FROM shared_link_folders WHERE shared_link_id = ?', [$shareLinkId]), 'folder_id');
    foreach ($rootIds as $rootId) {
        if (Scope::isWithinCustomerRoot($folderId, $rootId)) {
            return true;
        }
    }
    return false;
}

/** Avoids silently overwriting distinct files that happen to share a name at the zip root. */
function zip_unique_name(array $existingEntries, string $name): string
{
    $existingNames = array_column($existingEntries, 'name');
    if (!in_array($name, $existingNames, true)) {
        return $name;
    }
    $base = $name;
    $ext = '';
    $dot = strrpos($name, '.');
    if ($dot !== false) {
        $base = substr($name, 0, $dot);
        $ext = substr($name, $dot);
    }
    $i = 2;
    while (in_array("{$base} ({$i}){$ext}", $existingNames, true)) {
        $i++;
    }
    return "{$base} ({$i}){$ext}";
}

function zip_collect_folder_recursive(array $folder, string $pathPrefix, array &$entries, array &$seenFileIds): void
{
    $files = Db::query('SELECT * FROM files WHERE parent_id = ? AND deleted_at IS NULL', [$folder['id']]);
    foreach ($files as $file) {
        if ($file['drive_file_id'] === null || in_array($file['id'], $seenFileIds, true)) {
            continue;
        }
        $entries[] = [
            'name' => $pathPrefix . '/' . $file['name'],
            'driveFileId' => $file['drive_file_id'],
            'size' => (int) $file['size_bytes'],
        ];
        $seenFileIds[] = $file['id'];
    }
    $subFolders = Db::query('SELECT * FROM folders WHERE parent_id = ? AND deleted_at IS NULL', [$folder['id']]);
    foreach ($subFolders as $sub) {
        zip_collect_folder_recursive($sub, $pathPrefix . '/' . $sub['name'], $entries, $seenFileIds);
    }
}

/**
 * @return array{entries: array, directFileIds: array, processedFolderIds: array}
 *
 * directFileIds / processedFolderIds are kept separate on purpose: a file the
 * user explicitly picked counts as a FILE download in the stats panel, while a
 * file that merely happens to live inside an explicitly-picked FOLDER should
 * only bump that folder's own count — not every file inside it individually
 * (otherwise downloading one folder with 50 files explodes into 50 rows in the
 * download-stats list instead of the one folder the user actually downloaded).
 */
function zip_collect_entries(array $fileIds, array $folderIds): array
{
    $entries = [];
    $seenFileIds = [];
    $directFileIds = [];
    $processedFolderIds = [];

    if (!empty($fileIds)) {
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $rows = Db::query("SELECT * FROM files WHERE id IN ($placeholders) AND deleted_at IS NULL", $fileIds);
        foreach ($rows as $row) {
            if ($row['drive_file_id'] === null) {
                continue;
            }
            $entries[] = [
                'name' => zip_unique_name($entries, $row['name']),
                'driveFileId' => $row['drive_file_id'],
                'size' => (int) $row['size_bytes'],
            ];
            $seenFileIds[] = $row['id'];
            $directFileIds[] = $row['id'];
        }
    }

    foreach ($folderIds as $rootFolderId) {
        $root = Db::queryOne('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL', [$rootFolderId]);
        if ($root === null) {
            continue;
        }
        zip_collect_folder_recursive($root, $root['name'], $entries, $seenFileIds);
        $processedFolderIds[] = $root['id'];
    }

    return ['entries' => $entries, 'directFileIds' => $directFileIds, 'processedFolderIds' => $processedFolderIds];
}

function zip_download(array $params): void
{
    $fileIds = array_values(array_filter((array) ($_GET['fileIds'] ?? []), 'is_string'));
    $folderIds = array_values(array_filter((array) ($_GET['folderIds'] ?? []), 'is_string'));
    if (empty($fileIds) && empty($folderIds)) {
        Response::error('İndirilecek dosya veya klasör seçilmedi.', 422);
    }

    $user = Auth::currentUser();
    if ($user !== null) {
        foreach ($folderIds as $fid) {
            Scope::assertFolderAccessible($user, $fid);
        }
        foreach ($fileIds as $fid) {
            $file = Db::queryOne('SELECT parent_id FROM files WHERE id = ?', [$fid]);
            if ($file === null) {
                Response::error('Dosya bulunamadı.', 404);
            }
            Scope::assertFolderAccessible($user, $file['parent_id']);
        }
    } else {
        $shareLinkId = Auth::currentShareLinkId();
        if ($shareLinkId === null) {
            Response::error('Oturum açmanız gerekiyor.', 401);
        }
        foreach ($fileIds as $fid) {
            $file = Db::queryOne('SELECT * FROM files WHERE id = ?', [$fid]);
            if ($file === null || !shared_links_grants_file($shareLinkId, $file)) {
                Response::error('Yetkisiz dosya.', 403);
            }
        }
        foreach ($folderIds as $fid) {
            if (!zip_folder_in_share_scope($shareLinkId, $fid)) {
                Response::error('Yetkisiz klasör.', 403);
            }
        }
    }

    $collected = zip_collect_entries($fileIds, $folderIds);
    $entries = $collected['entries'];
    if (empty($entries)) {
        Response::error('İndirilebilir gerçek dosya bulunamadı.', 404);
    }

    // ZipStreamer writes zip64 unconditionally, so the old 4GB-per-field format
    // limit no longer applies — this is now purely an abuse/sanity ceiling, not a
    // technical one.
    $totalSize = array_sum(array_column($entries, 'size'));
    $maxZipBytes = 200 * 1024 * 1024 * 1024;
    if ($totalSize > $maxZipBytes) {
        Response::error(
            'Seçilen dosyalar tek bir ZIP için çok büyük (' . round($totalSize / 1073741824, 2) . ' GB). Lütfen dosyaları daha küçük gruplar halinde seçip tekrar deneyin.',
            413
        );
    }

    if ($user !== null) {
        AuditLogger::log($user['id'], $user['name'], $user['role'], 'BULK_DOWNLOAD', count($entries) . ' dosya ZIP olarak indirildi.');
    }
    if (!empty($collected['directFileIds'])) {
        $idPlaceholders = implode(',', array_fill(0, count($collected['directFileIds']), '?'));
        Db::execute("UPDATE files SET download_count = download_count + 1 WHERE id IN ($idPlaceholders)", $collected['directFileIds']);
    }
    if (!empty($collected['processedFolderIds'])) {
        $idPlaceholders = implode(',', array_fill(0, count($collected['processedFolderIds']), '?'));
        Db::execute("UPDATE folders SET download_count = download_count + 1 WHERE id IN ($idPlaceholders)", $collected['processedFolderIds']);
    }

    $requestedName = trim((string) ($_GET['name'] ?? ''));
    // Strip path separators / control characters — this becomes an HTTP header and
    // a filesystem name on the customer's machine.
    $safeName = preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]/', '', $requestedName);
    $downloadName = $safeName !== '' ? $safeName : 'RUF_Drive_Secilmisler';
    if (!preg_match('/\.zip$/i', $downloadName)) {
        $downloadName .= '.zip';
    }

    @set_time_limit(0);
    ZipStreamer::stream($entries, $downloadName);
    exit;
}

return [
    ['GET', '#^/download-zip$#', 'zip_download'],
];
