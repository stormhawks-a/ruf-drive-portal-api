<?php

/** Drive parent id for a new folder: the parent folder's mirror, or the app-wide root if top-level. */
function folders_resolve_drive_parent(?string $parentId): ?string
{
    if ($parentId !== null) {
        $parent = Db::queryOne('SELECT drive_folder_id FROM folders WHERE id = ?', [$parentId]);
        return $parent['drive_folder_id'] ?? null;
    }
    $setting = Db::queryOne('SELECT value FROM app_settings WHERE `key` = ?', ['drive_root_folder_id']);
    return $setting['value'] ?? null;
}

/** Every folder id in the subtree rooted at $rootId (itself included), regardless of trash state. */
function folders_collect_descendant_ids(string $rootId): array
{
    $allIds = [$rootId];
    $frontier = [$rootId];
    $guard = 0;
    while (!empty($frontier) && $guard < 200) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $children = Db::query("SELECT id FROM folders WHERE parent_id IN ($placeholders)", $frontier);
        $childIds = array_values(array_diff(array_column($children, 'id'), $allIds));
        if (empty($childIds)) {
            break;
        }
        $allIds = array_merge($allIds, $childIds);
        $frontier = $childIds;
        $guard++;
    }
    return $allIds;
}

function folders_list(array $params): void
{
    $user = Auth::requireAuth();

    if (isset($_GET['all'])) {
        $rows = Db::query('SELECT * FROM folders ORDER BY created_at ASC');
        if ($user['role'] === 'CUSTOMER') {
            $rootId = $user['folder_id'];
            $rows = array_values(array_filter($rows, function ($f) use ($rootId) {
                return $f['id'] === $rootId || Scope::isWithinCustomerRoot($f['id'], $rootId);
            }));
        }
        Response::json(['folders' => $rows]);
        return;
    }

    $parentId = $_GET['parentId'] ?? null;

    if ($user['role'] === 'CUSTOMER') {
        if ($parentId === null) {
            $parentId = $user['folder_id'];
        } else {
            Scope::assertFolderAccessible($user, $parentId);
        }
    }

    if ($parentId === null) {
        $rows = Db::query('SELECT * FROM folders WHERE parent_id IS NULL AND deleted_at IS NULL ORDER BY created_at ASC');
    } else {
        $rows = Db::query('SELECT * FROM folders WHERE parent_id = ? AND deleted_at IS NULL ORDER BY created_at ASC', [$parentId]);
    }
    Response::json(['folders' => $rows]);
}

function folders_create(array $params): void
{
    $user = Auth::requireAuth();
    if (!in_array($user['role'], ['ADMIN', 'EDITOR', 'CUSTOMER'], true)) {
        Response::error('Yetkisiz.', 403);
    }
    $body = Response::body();
    $name = trim((string) ($body['name'] ?? ''));
    $parentId = $body['parentId'] ?? null;

    if ($name === '') {
        Response::error('Klasör adı zorunlu.', 422);
    }
    if ($user['role'] === 'CUSTOMER') {
        $parentId = $parentId ?? $user['folder_id'];
        Scope::assertFolderAccessible($user, $parentId);
    }

    $id = Ids::generate('folder');
    Db::execute('INSERT INTO folders (id, name, parent_id) VALUES (?, ?, ?)', [$id, $name, $parentId]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FOLDER_CREATE', "Klasör oluşturuldu: {$name}");

    // Best-effort Drive mirror: the folder already exists in our DB regardless of
    // whether this succeeds, so a transient Drive/network error never blocks the user.
    try {
        $driveParentId = folders_resolve_drive_parent($parentId);
        if ($driveParentId !== null) {
            $driveFolderId = GoogleDriveClient::createFolder($name, $driveParentId);
            Db::execute('UPDATE folders SET drive_folder_id = ? WHERE id = ?', [$driveFolderId, $id]);
        }
    } catch (Throwable $e) {
        error_log('Drive klasor aynalama basarisiz: ' . $e->getMessage());
    }

    $row = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    Response::json(['folder' => $row], 201);
}

function folders_update(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    if ($folder === null) {
        Response::error('Klasör bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $id);

    $body = Response::body();
    if (array_key_exists('name', $body)) {
        $name = trim((string) $body['name']);
        if ($name === '') {
            Response::error('Klasör adı boş olamaz.', 422);
        }
        Db::execute('UPDATE folders SET name = ? WHERE id = ?', [$name, $id]);
        AuditLogger::log($user['id'], $user['name'], $user['role'], 'FOLDER_RENAME', "Klasör yeniden adlandırıldı: {$folder['name']} -> {$name}");
    }

    if (array_key_exists('parentId', $body)) {
        $newParentId = $body['parentId'];
        if ($newParentId === $folder['parent_id']) {
            Response::json(['ok' => true]);
            return;
        }
        if ($newParentId !== null) {
            $newParent = Db::queryOne('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL', [$newParentId]);
            if ($newParent === null) {
                Response::error('Hedef klasör bulunamadı.', 404);
            }
            Scope::assertFolderAccessible($user, $newParentId);
            // A folder can never be moved into itself or one of its own descendants —
            // that would detach the subtree from the tree root while every FK still
            // points "down" into it, which corrupts every recursive walk (delete,
            // restore, size, preview icons) into an infinite loop.
            if (in_array($newParentId, folders_collect_descendant_ids($id), true)) {
                Response::error('Bir klasör kendi alt klasörünün içine taşınamaz.', 422);
            }
        }

        $oldParentId = $folder['parent_id'];
        Db::execute('UPDATE folders SET parent_id = ? WHERE id = ?', [$newParentId, $id]);
        AuditLogger::log($user['id'], $user['name'], $user['role'], 'FOLDER_MOVE', "Klasör taşındı: {$folder['name']}");

        // Best-effort Drive mirror, same tolerance as folders_create: the move
        // already succeeded in our own DB regardless of whether Drive's side
        // works, so a transient Drive/network error never blocks the user.
        try {
            if ($folder['drive_folder_id'] !== null) {
                $oldDriveParentId = folders_resolve_drive_parent($oldParentId);
                $newDriveParentId = folders_resolve_drive_parent($newParentId);
                if ($newDriveParentId !== null) {
                    GoogleDriveClient::moveFile($folder['drive_folder_id'], $oldDriveParentId, $newDriveParentId);
                }
            }
        } catch (Throwable $e) {
            error_log('Drive klasor tasima basarisiz: ' . $e->getMessage());
        }
    }

    Response::json(['ok' => true]);
}

/**
 * Recursively clones one folder's whole subtree (itself, every nested folder,
 * every file at any depth) under $newParentId — a real Drive-side copy for
 * every file and folder (GoogleDriveClient::createFolder/copyFile), never a
 * second DB row sharing a drive id with the original, so the two trees are
 * fully independent afterward. Appends every newly created row into
 * &$createdFolders/&$createdFiles so the caller can hand the whole batch back
 * to the frontend in one response (unlike a move, the frontend has no local
 * copy of any of these new rows to patch in optimistically).
 */
function folders_copy_subtree(
    string $sourceFolderId,
    string $newParentId,
    ?string $newDriveParentId,
    array $actingUser,
    array &$createdFolders,
    array &$createdFiles
): string {
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$sourceFolderId]);

    $newId = Ids::generate('folder');
    $newDriveFolderId = null;
    if ($newDriveParentId !== null) {
        try {
            $newDriveFolderId = GoogleDriveClient::createFolder($folder['name'], $newDriveParentId);
        } catch (Throwable $e) {
            error_log('Drive klasor kopyalama basarisiz: ' . $e->getMessage());
        }
    }
    Db::execute('INSERT INTO folders (id, name, parent_id, drive_folder_id) VALUES (?, ?, ?, ?)', [$newId, $folder['name'], $newParentId, $newDriveFolderId]);
    $createdFolders[] = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$newId]);

    $files = Db::query('SELECT * FROM files WHERE parent_id = ? AND deleted_at IS NULL', [$sourceFolderId]);
    foreach ($files as $file) {
        $newFileId = Ids::generate('file');
        $newDriveFileId = null;
        if ($file['drive_file_id'] !== null && $newDriveFolderId !== null) {
            try {
                $newDriveFileId = GoogleDriveClient::copyFile($file['drive_file_id'], $file['name'], $newDriveFolderId);
            } catch (Throwable $e) {
                error_log('Drive dosya kopyalama basarisiz: ' . $e->getMessage());
            }
        }
        Db::execute(
            'INSERT INTO files (id, name, original_name, size_bytes, mime_type, file_type, parent_id, owner_id, drive_file_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$newFileId, $file['name'], $file['original_name'], $file['size_bytes'], $file['mime_type'], $file['file_type'], $newId, $actingUser['id'], $newDriveFileId]
        );
        $createdFiles[] = Db::queryOne('SELECT * FROM files WHERE id = ?', [$newFileId]);
    }

    $subfolders = Db::query('SELECT id FROM folders WHERE parent_id = ? AND deleted_at IS NULL', [$sourceFolderId]);
    foreach ($subfolders as $sub) {
        folders_copy_subtree($sub['id'], $newId, $newDriveFolderId, $actingUser, $createdFolders, $createdFiles);
    }

    return $newId;
}

function folders_copy(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL', [$id]);
    if ($folder === null) {
        Response::error('Klasör bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $id);

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

    // Same cycle guard as move: copying a folder into its own subtree would mean
    // the freshly-created copy immediately becomes one of the folders left to
    // copy, recursing forever.
    if (in_array($destParentId, folders_collect_descendant_ids($id), true)) {
        Response::error('Bir klasör kendi alt klasörünün içine kopyalanamaz.', 422);
    }

    // A folder with many nested files means many sequential Drive API calls —
    // easily longer than PHP's default execution-time cap on a normal request.
    @set_time_limit(0);

    $createdFolders = [];
    $createdFiles = [];
    $newId = folders_copy_subtree($id, $destParentId, $destFolder['drive_folder_id'], $user, $createdFolders, $createdFiles);

    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FOLDER_COPY', "Klasör kopyalandı: {$folder['name']}");

    Response::json([
        'folder' => Db::queryOne('SELECT * FROM folders WHERE id = ?', [$newId]),
        'allFolders' => $createdFolders,
        'allFiles' => $createdFiles,
    ], 201);
}

function folders_delete(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    if ($folder === null) {
        Response::error('Klasör bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $id);

    // Cascade the same soft-delete to every descendant folder/file so the trash is
    // consistent after a page reload — previously only this one row was marked
    // deleted, so a refresh made nested items reappear active (but unreachable,
    // since their parent was hidden) instead of showing up in the trash.
    // Items already in the trash (deleted independently, earlier) are left alone.
    $descendantIds = folders_collect_descendant_ids($id);

    // Staff browsing into a customer's own folder tree (via the admin dashboard,
    // not a share link) still gets attributed to that customer in deleted_by —
    // see Scope::resolveOwningCustomerId's own docblock. A real customer deleting
    // their own folder, or staff deleting something outside any customer's tree,
    // is unaffected (owningCustomerId is null there, or equals $user['id']).
    $deletedById = $user['id'];
    if ($user['role'] !== 'CUSTOMER') {
        $owningCustomerId = Scope::resolveOwningCustomerId($id);
        if ($owningCustomerId !== null) {
            $deletedById = $owningCustomerId;
        }
    }

    // NOW() (MySQL's own clock), not PHP's date() — PHP defaults to UTC with no
    // timezone configured anywhere in this app, while MySQL's CURRENT_TIMESTAMP
    // (used for created_at etc.) follows the server's local timezone. Mixing the
    // two produced a deleted_at that could read as *before* created_at by exactly
    // the UTC offset (3 hours on this server) — cosmetic on its own, but the kind
    // of clock mismatch that can quietly break anything comparing timestamps.
    $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));
    Db::execute(
        "UPDATE folders SET deleted_at = NOW(), deleted_by = ? WHERE id IN ($placeholders) AND deleted_at IS NULL",
        array_merge([$deletedById], $descendantIds)
    );
    Db::execute(
        "UPDATE files SET deleted_at = NOW(), deleted_by = ? WHERE parent_id IN ($placeholders) AND deleted_at IS NULL",
        array_merge([$deletedById], $descendantIds)
    );
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_DELETE', "Klasör çöp kutusuna taşındı: {$folder['name']}");
    Response::json(['ok' => true]);
}

/** Staff-only, irreversible — actually deletes the rows and the Drive files/folders
    for the whole subtree, not just a soft-delete. Only allowed on something already
    in the trash, same guard files_permanent_delete uses. Descendant folders/files
    are collected and their Drive ids removed BEFORE any DB row is deleted (once the
    root folder row is gone, the FK cascade would silently take the rest with it,
    leaving no chance to look up their drive_folder_id/drive_file_id afterward). */
function folders_permanent_delete(array $params): void
{
    $user = Auth::requireRole('ADMIN');
    $id = $params['id'];
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    if ($folder === null) {
        // Idempotent, same reasoning as files_permanent_delete: a folder whose
        // OWN ancestor is also in the same bulk batch can already be gone by
        // the time this request lands (the ancestor's cascade delete got there
        // first) — the caller's desired end state (this row gone) is already
        // true, so this succeeds quietly instead of 404-ing.
        Response::json(['ok' => true]);
    }
    if ($folder['deleted_at'] === null) {
        Response::error('Sadece çöp kutusundaki klasörler kalıcı olarak silinebilir.', 422);
    }

    $descendantIds = folders_collect_descendant_ids($id);
    $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));

    // Look up every descendant file/folder's Drive id BEFORE deleting any DB
    // rows below — once those rows are gone there's no way to look this back
    // up (the FK cascade would silently take it with them).
    $files = Db::query("SELECT id, drive_file_id FROM files WHERE parent_id IN ($placeholders)", $descendantIds);
    $descendantFolders = Db::query("SELECT id, drive_folder_id FROM folders WHERE id IN ($placeholders)", $descendantIds);

    Db::execute("DELETE FROM files WHERE parent_id IN ($placeholders)", $descendantIds);
    Db::execute("DELETE FROM folders WHERE id IN ($placeholders)", $descendantIds);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'PERMISSION_CHANGE', "Klasör kalıcı olarak silindi: {$folder['name']}");

    // Respond immediately — the DB rows (what the user is actually waiting to
    // see disappear) are already gone. A folder can contain a very large file
    // that makes Drive take longer to acknowledge its delete than this host's
    // own gateway/PHP-FPM timeout allows a request to wait for (not something
    // adjustable from PHP's own max_execution_time — that ceiling is enforced
    // by infrastructure in front of PHP). Finishing the response now and
    // running every Drive-side delete afterward, off the client's back, means
    // this request can never time out no matter how many/large the files are.
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    foreach ($files as $file) {
        if ($file['drive_file_id'] !== null) {
            try {
                GoogleDriveClient::deleteFile($file['drive_file_id']);
            } catch (Throwable $e) {
                error_log('Kalıcı silme: Drive dosya silme hatası ' . $file['id'] . ': ' . $e->getMessage());
            }
        }
    }
    foreach ($descendantFolders as $f) {
        if ($f['drive_folder_id'] !== null) {
            try {
                GoogleDriveClient::deleteFile($f['drive_folder_id']);
            } catch (Throwable $e) {
                error_log('Kalıcı silme: Drive klasör silme hatası ' . $f['id'] . ': ' . $e->getMessage());
            }
        }
    }
    exit;
}

function folders_restore(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    Scope::assertFolderAccessible($user, $id);
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    if ($folder === null) {
        Response::error('Klasör bulunamadı.', 404);
    }
    $deletedAt = $folder['deleted_at'];

    // Collect the full subtree — INCLUDING $id itself — before touching anything.
    // Files sitting directly inside the folder being restored have parent_id === $id,
    // so $id must stay in the id list used for the files cascade below; a previous
    // version of this excluded $id from that list (to avoid a redundant no-op update
    // on the folders side) which silently meant files directly in the restored folder
    // itself were never restored, only files in deeper subfolders.
    $allIds = folders_collect_descendant_ids($id);

    Db::execute('UPDATE folders SET deleted_at = NULL, deleted_by = NULL WHERE id = ?', [$id]);

    if ($deletedAt !== null) {
        // Only cascade-restore items that were trashed in the very same delete
        // operation (same timestamp) — anything trashed independently, before or
        // after, stays in the trash.
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        Db::execute(
            "UPDATE folders SET deleted_at = NULL, deleted_by = NULL WHERE id IN ($placeholders) AND deleted_at = ?",
            array_merge($allIds, [$deletedAt])
        );
        Db::execute(
            "UPDATE files SET deleted_at = NULL, deleted_by = NULL WHERE parent_id IN ($placeholders) AND deleted_at = ?",
            array_merge($allIds, [$deletedAt])
        );
    }
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FOLDER_RESTORE', "Klasör çöp kutusundan geri yüklendi.");
    Response::json(['ok' => true]);
}

/**
 * Registers one folder-level download for the stats leaderboard. The browser's
 * native "download as real folder structure" path (File System Access API,
 * see src/lib/folderDownload.ts) fetches each file inside individually rather
 * than going through the ZIP endpoint — those individual fetches pass
 * ?skipCount=1 so files_download doesn't count each one separately, and the
 * frontend calls this once per top-level folder instead, exactly mirroring
 * how zip_download counts a folder once regardless of how many files it holds.
 */
function folders_register_download(array $params): void
{
    $user = Auth::currentUser() ?? shared_links_resolve_acting_user();
    if ($user === null) {
        Response::error('Oturum açmanız gerekiyor.', 401);
    }
    $id = $params['id'];
    Scope::assertFolderAccessible($user, $id);
    Db::execute('UPDATE folders SET download_count = download_count + 1 WHERE id = ?', [$id]);
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/folders$#', 'folders_list'],
    ['POST', '#^/folders$#', 'folders_create'],
    ['PUT', '#^/folders/(?P<id>[a-zA-Z0-9_]+)$#', 'folders_update'],
    ['DELETE', '#^/folders/(?P<id>[a-zA-Z0-9_]+)$#', 'folders_delete'],
    ['DELETE', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/permanent$#', 'folders_permanent_delete'],
    ['POST', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/restore$#', 'folders_restore'],
    ['POST', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/register-download$#', 'folders_register_download'],
    ['POST', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/copy$#', 'folders_copy'],
];
