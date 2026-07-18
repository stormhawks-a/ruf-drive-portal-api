<?php

/**
 * Hard-deletes anything that's been sitting in the trash for 30+ days — Drive
 * file/folder bytes included, not just the DB rows. Nothing else in this app
 * ever does this; `folders_delete`/`files_delete` are soft-deletes only, so
 * without this, trashed items would sit here forever despite the UI's own
 * "Silinen öğeler 30 gün saklanır" promise.
 *
 * Meant to be hit once a day by a cPanel Cron Job (`curl`/`wget` against this
 * URL with the shared secret) — there's no persistent process on this shared
 * host to run it any other way. Auth is a plain shared-secret token, not a
 * session, since a cron job has neither a login nor a browser cookie jar.
 */
function trash_purge(array $params): void
{
    $expectedToken = Config::get('cron_secret');
    $providedToken = $_GET['token'] ?? '';
    if (!$expectedToken || !is_string($providedToken) || !hash_equals((string) $expectedToken, $providedToken)) {
        Response::error('Yetkisiz.', 401);
    }

    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

    // Files first, then folders — a file whose parent folder is also expired
    // always shares that folder's exact deleted_at (folders_delete cascades the
    // same timestamp to every descendant at delete time), so this order never
    // orphans anything; it's just the safer direction given
    // fk_files_parent/fk_folders_parent are both ON DELETE CASCADE.
    $expiredFiles = Db::query('SELECT id, drive_file_id FROM files WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]);
    $expiredFolders = Db::query('SELECT id, drive_folder_id FROM folders WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]);

    $deletedFiles = 0;
    foreach ($expiredFiles as $file) {
        if ($file['drive_file_id'] !== null) {
            try {
                GoogleDriveClient::deleteFile($file['drive_file_id']);
            } catch (Throwable $e) {
                error_log('Trash purge: dosya Drive silme hatasi ' . $file['id'] . ': ' . $e->getMessage());
            }
        }
        Db::execute('DELETE FROM files WHERE id = ?', [$file['id']]);
        $deletedFiles++;
    }

    $deletedFolders = 0;
    foreach ($expiredFolders as $folder) {
        if ($folder['drive_folder_id'] !== null) {
            try {
                // Deleting a Drive folder recursively removes anything still
                // inside it on Drive's side too — harmless if its children were
                // already removed individually above (Drive just 404s, caught
                // below), necessary if any weren't.
                GoogleDriveClient::deleteFile($folder['drive_folder_id']);
            } catch (Throwable $e) {
                error_log('Trash purge: klasor Drive silme hatasi ' . $folder['id'] . ': ' . $e->getMessage());
            }
        }
        Db::execute('DELETE FROM folders WHERE id = ?', [$folder['id']]);
        $deletedFolders++;
    }

    if ($deletedFiles > 0 || $deletedFolders > 0) {
        AuditLogger::log(null, 'Sistem', 'ADMIN', 'PERMISSION_CHANGE', "Çöp kutusu otomatik temizlendi: {$deletedFolders} klasör, {$deletedFiles} dosya (30 günden eski).");
    }

    Response::json(['ok' => true, 'deletedFolders' => $deletedFolders, 'deletedFiles' => $deletedFiles]);
}

return [
    ['GET', '#^/trash/purge$#', 'trash_purge'],
];
