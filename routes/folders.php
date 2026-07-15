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
    Response::json(['ok' => true]);
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
    // NOW() (MySQL's own clock), not PHP's date() — PHP defaults to UTC with no
    // timezone configured anywhere in this app, while MySQL's CURRENT_TIMESTAMP
    // (used for created_at etc.) follows the server's local timezone. Mixing the
    // two produced a deleted_at that could read as *before* created_at by exactly
    // the UTC offset (3 hours on this server) — cosmetic on its own, but the kind
    // of clock mismatch that can quietly break anything comparing timestamps.
    $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));
    Db::execute(
        "UPDATE folders SET deleted_at = NOW(), deleted_by = ? WHERE id IN ($placeholders) AND deleted_at IS NULL",
        array_merge([$user['id']], $descendantIds)
    );
    Db::execute(
        "UPDATE files SET deleted_at = NOW(), deleted_by = ? WHERE parent_id IN ($placeholders) AND deleted_at IS NULL",
        array_merge([$user['id']], $descendantIds)
    );
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_DELETE', "Klasör çöp kutusuna taşındı: {$folder['name']}");
    Response::json(['ok' => true]);
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
    ['POST', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/restore$#', 'folders_restore'],
    ['POST', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/register-download$#', 'folders_register_download'],
];
