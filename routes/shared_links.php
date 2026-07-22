<?php

/** Loads a share link row, rejecting revoked/expired ones with the right HTTP error. */
function shared_links_load_valid(string $id): array
{
    $link = Db::queryOne('SELECT * FROM shared_links WHERE id = ?', [$id]);
    if ($link === null || $link['revoked_at'] !== null) {
        Response::error('Bu paylaşım bağlantısı artık geçerli değil.', 404);
    }
    if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) {
        Response::error('Bu paylaşım bağlantısının süresi dolmuş.', 410);
    }
    return $link;
}

/**
 * A "Müşteri" view-mode share link visitor has no real account (no login), but
 * should be able to act exactly as that customer (rename/delete/restore/share
 * within their own scope) — resolves the real customer row so existing
 * Scope::assertFolderAccessible()/AuditLogger calls work unchanged. Returns null
 * for a "Tüketici" view-mode share (download-only) or no active share session.
 */
function shared_links_resolve_acting_user(): ?array
{
    $shareLinkId = Auth::currentShareLinkId();
    if ($shareLinkId === null) {
        return null;
    }
    $link = Db::queryOne('SELECT * FROM shared_links WHERE id = ?', [$shareLinkId]);
    if ($link === null || $link['revoked_at'] !== null) {
        return null;
    }
    if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) {
        return null;
    }
    if ($link['view_mode'] !== 'customer' || $link['customer_user_id'] === null) {
        return null;
    }
    return Db::queryOne('SELECT * FROM users WHERE id = ?', [$link['customer_user_id']]);
}

/**
 * Metadata only — direct file/folder ids, never the password hash. `$includePassword`
 * additionally decrypts and includes the current plaintext password (via
 * `password_encrypted`) — only ever pass true from staff-only endpoints, never
 * anything reachable by an anonymous share-link visitor.
 */
function shared_links_serialize(array $link, bool $unlocked, bool $includePassword = false): array
{
    $fileIds = array_column(Db::query('SELECT file_id FROM shared_link_files WHERE shared_link_id = ?', [$link['id']]), 'file_id');
    $folderIds = array_column(Db::query('SELECT folder_id FROM shared_link_folders WHERE shared_link_id = ?', [$link['id']]), 'folder_id');
    $result = [
        'id' => $link['id'],
        'name' => $link['name'],
        'recipientName' => $link['recipient_name'],
        'hasPassword' => $link['password_hash'] !== null,
        'unlocked' => $unlocked,
        'expiresAt' => $link['expires_at'],
        'createdAt' => $link['created_at'],
        'downloadCount' => (int) $link['download_count'],
        'viewMode' => $link['view_mode'],
        'customerId' => $link['customer_user_id'],
        'fileIds' => array_values($fileIds),
        'folderIds' => array_values($folderIds),
        'allowPreview' => (bool) $link['allow_preview'],
    ];
    if ($includePassword) {
        $result['currentPassword'] = $link['password_encrypted'] !== null ? Crypto::decrypt($link['password_encrypted']) : null;
    }
    return $result;
}

/**
 * Directly-shared folders/files plus every descendant folder and the files inside them.
 *
 * A "Tüketici" (consumer) link is a bare download recipient — it must never see
 * anything the owner has trashed. A "Müşteri" (customer) link is a real, full
 * customer session (rename/delete/restore) — it needs its own trash included,
 * otherwise every page refresh silently drops whatever it had just deleted (the
 * item vanishes instead of showing up in the trash view).
 */
function shared_links_collect_content(array $link): array
{
    $includeDeleted = $link['view_mode'] === 'customer';
    $deletedFilter = $includeDeleted ? '' : ' AND deleted_at IS NULL';

    $folderIds = array_column(Db::query('SELECT folder_id FROM shared_link_folders WHERE shared_link_id = ?', [$link['id']]), 'folder_id');
    $fileIds = array_column(Db::query('SELECT file_id FROM shared_link_files WHERE shared_link_id = ?', [$link['id']]), 'file_id');

    $allFolderIds = array_values($folderIds);
    $frontier = $allFolderIds;
    $guard = 0;
    while (!empty($frontier) && $guard < 200) {
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $children = Db::query("SELECT id FROM folders WHERE parent_id IN ($placeholders)$deletedFilter", $frontier);
        $childIds = array_values(array_diff(array_column($children, 'id'), $allFolderIds));
        if (empty($childIds)) {
            break;
        }
        $allFolderIds = array_merge($allFolderIds, $childIds);
        $frontier = $childIds;
        $guard++;
    }

    $folders = [];
    if (!empty($allFolderIds)) {
        $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));
        $folders = Db::query("SELECT * FROM folders WHERE id IN ($placeholders)$deletedFilter", $allFolderIds);
    }

    $files = [];
    $seenFileIds = [];
    if (!empty($fileIds)) {
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $files = Db::query("SELECT * FROM files WHERE id IN ($placeholders)$deletedFilter", array_values($fileIds));
        $seenFileIds = array_column($files, 'id');
    }
    if (!empty($allFolderIds)) {
        $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));
        $inFolderFiles = Db::query("SELECT * FROM files WHERE parent_id IN ($placeholders)$deletedFilter", $allFolderIds);
        foreach ($inFolderFiles as $f) {
            if (!in_array($f['id'], $seenFileIds, true)) {
                $files[] = $f;
                $seenFileIds[] = $f['id'];
            }
        }
    }

    return ['folders' => array_values($folders), 'files' => array_values($files)];
}

/** Used by files.php's download route to authorize anonymous share-link access. */
function shared_links_grants_file(string $shareLinkId, array $file): bool
{
    $link = Db::queryOne('SELECT * FROM shared_links WHERE id = ?', [$shareLinkId]);
    if ($link === null || $link['revoked_at'] !== null) {
        return false;
    }
    if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) {
        return false;
    }
    // "Musteri" (full-panel) links deliberately mirror the customer's own trash too
    // (see shared_links_collect_content) — but a "Tuketici" (download-only) link must
    // lose access to a file the instant it's trashed, even if the visitor already
    // knows its id from before the delete. Without this, files_download/files_thumbnail
    // would keep serving it for as long as the file sits in the 30-day trash window.
    if ($file['deleted_at'] !== null && $link['view_mode'] !== 'customer') {
        return false;
    }

    $direct = Db::queryOne('SELECT 1 FROM shared_link_files WHERE shared_link_id = ? AND file_id = ?', [$shareLinkId, $file['id']]);
    if ($direct !== null) {
        return true;
    }

    $folderIds = array_column(Db::query('SELECT folder_id FROM shared_link_folders WHERE shared_link_id = ?', [$shareLinkId]), 'folder_id');
    foreach ($folderIds as $rootFolderId) {
        if (Scope::isWithinCustomerRoot($file['parent_id'], $rootFolderId)) {
            return true;
        }
    }
    return false;
}

function shared_links_create(array $params): void
{
    // Staff: unrestricted, may target any customer and pick either view mode.
    // Real customer login or a "Müşteri" view-mode share visitor: may only share
    // their own content, and only ever as a plain "Tüketici" (download-only) link —
    // they can never grant magic full-panel access to someone else.
    $user = Auth::currentUser();
    $isStaff = $user !== null && in_array($user['role'], ['ADMIN', 'EDITOR'], true);
    if (!$isStaff) {
        if ($user === null || $user['role'] !== 'CUSTOMER') {
            $user = shared_links_resolve_acting_user();
        }
        if ($user === null) {
            Response::error('Oturum açmanız gerekiyor.', 401);
        }
    }

    $body = Response::body();

    $name = trim((string) ($body['name'] ?? ''));
    $fileIds = array_values(array_filter((array) ($body['fileIds'] ?? []), 'is_string'));
    $folderIds = array_values(array_filter((array) ($body['folderIds'] ?? []), 'is_string'));
    if ($name === '' || (empty($fileIds) && empty($folderIds))) {
        Response::error('Paylaşım adı ve en az bir dosya/klasör zorunlu.', 422);
    }

    if (!$isStaff) {
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
    }

    $recipientName = trim((string) ($body['recipientName'] ?? '')) ?: null;
    $password = trim((string) ($body['password'] ?? '')) ?: null;
    $expiresAtRaw = $body['expiresAt'] ?? null;
    $expiresAt = $expiresAtRaw ? date('Y-m-d H:i:s', strtotime((string) $expiresAtRaw)) : null;

    if ($isStaff) {
        $viewMode = ($body['viewMode'] ?? 'consumer') === 'customer' ? 'customer' : 'consumer';
        $customerId = trim((string) ($body['customerId'] ?? '')) ?: null;
        if ($customerId !== null) {
            $customer = Db::queryOne("SELECT id FROM users WHERE id = ? AND role = 'CUSTOMER'", [$customerId]);
            if ($customer === null) {
                Response::error('Müşteri bulunamadı.', 404);
            }
        }
        // Only ever meaningful for a "Tüketici" link — a "Müşteri" link already
        // grants full browsing (and more, real edit rights), so this flag would be
        // redundant there.
        $allowPreview = $viewMode === 'consumer' && !empty($body['allowPreview']);
    } else {
        $viewMode = 'consumer';
        $customerId = null;
        $allowPreview = false;
    }

    $id = Ids::generate('link');
    Db::execute(
        'INSERT INTO shared_links (id, name, created_by_id, recipient_name, password_hash, password_encrypted, expires_at, view_mode, customer_user_id, allow_preview) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $id, $name, $user['id'], $recipientName,
            $password !== null ? Auth::hash($password) : null,
            $password !== null ? Crypto::encrypt($password) : null,
            $expiresAt, $viewMode, $customerId, $allowPreview ? 1 : 0,
        ]
    );
    foreach ($fileIds as $fid) {
        Db::execute('INSERT IGNORE INTO shared_link_files (shared_link_id, file_id) VALUES (?, ?)', [$id, $fid]);
    }
    foreach ($folderIds as $fid) {
        Db::execute('INSERT IGNORE INTO shared_link_folders (shared_link_id, folder_id) VALUES (?, ?)', [$id, $fid]);
    }

    AuditLogger::log($user['id'], $user['name'], $user['role'], 'LINK_CREATE', "Yeni paylaşım bağlantısı üretildi: {$name}");

    $link = shared_links_load_valid($id);
    Response::json(['link' => shared_links_serialize($link, true)], 201);
}

/** Latest active (non-revoked, non-expired) persistent link for a given customer, if any. */
/**
 * "Müşteri" (full panel) and "Tüketici" (download-only) persistent links are
 * independent slots per customer, not one shared "the current link" — a
 * request must say which one it wants, so renewing/updating one mode never
 * touches the other's still-valid link.
 */
function shared_links_get_by_customer(array $params): void
{
    Auth::requireRole(['ADMIN', 'EDITOR']);
    $customerId = $params['customerId'];
    $viewMode = ($_GET['viewMode'] ?? 'customer') === 'consumer' ? 'consumer' : 'customer';
    $rows = Db::query(
        "SELECT * FROM shared_links WHERE customer_user_id = ? AND view_mode = ? AND revoked_at IS NULL
         AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 1",
        [$customerId, $viewMode]
    );
    if (empty($rows)) {
        Response::json(['link' => null]);
        return;
    }
    Response::json(['link' => shared_links_serialize($rows[0], true, true)]);
}

/** Staff-only: set or remove a persistent link's password in place, keeping the same id/URL. */
function shared_links_update_password(array $params): void
{
    $user = Auth::requireRole(['ADMIN', 'EDITOR']);
    $id = $params['id'];
    $link = Db::queryOne('SELECT * FROM shared_links WHERE id = ?', [$id]);
    if ($link === null) {
        Response::error('Paylaşım bağlantısı bulunamadı.', 404);
    }

    $body = Response::body();
    $password = trim((string) ($body['password'] ?? '')) ?: null;
    $hash = $password !== null ? Auth::hash($password) : null;
    $encrypted = $password !== null ? Crypto::encrypt($password) : null;
    Db::execute('UPDATE shared_links SET password_hash = ?, password_encrypted = ? WHERE id = ?', [$hash, $encrypted, $id]);

    $action = $password !== null ? 'şifresi güncellendi' : 'şifresi kaldırıldı';
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'LINK_CREATE', "Paylaşım bağlantısı {$action}: {$link['name']}");

    $updated = Db::queryOne('SELECT * FROM shared_links WHERE id = ?', [$id]);
    Response::json(['link' => shared_links_serialize($updated, true, true)]);
}

/** Staff-only: hasPassword flag for every customer's latest active persistent link, in one query — powers a lock badge on the customer card grid without an N+1 fetch per card. */
function shared_links_status_all(array $params): void
{
    Auth::requireRole(['ADMIN', 'EDITOR']);
    $rows = Db::query(
        "SELECT customer_user_id, password_hash FROM shared_links
         WHERE customer_user_id IS NOT NULL AND revoked_at IS NULL
         AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY created_at DESC"
    );
    $statuses = [];
    foreach ($rows as $row) {
        $cid = $row['customer_user_id'];
        if (!array_key_exists($cid, $statuses)) {
            $statuses[$cid] = $row['password_hash'] !== null;
        }
    }
    Response::json(['statuses' => $statuses]);
}

function shared_links_revoke(array $params): void
{
    $user = Auth::requireRole(['ADMIN', 'EDITOR']);
    $id = $params['id'];
    $link = Db::queryOne('SELECT * FROM shared_links WHERE id = ?', [$id]);
    if ($link === null) {
        Response::error('Paylaşım bağlantısı bulunamadı.', 404);
    }
    Db::execute('UPDATE shared_links SET revoked_at = NOW() WHERE id = ?', [$id]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'LINK_CREATE', "Paylaşım bağlantısı iptal edildi: {$link['name']}");
    Response::json(['ok' => true]);
}

function shared_links_get(array $params): void
{
    $id = $params['id'];
    $link = shared_links_load_valid($id);

    $hasPassword = $link['password_hash'] !== null;
    $alreadyUnlocked = Auth::currentShareLinkId() === $id;

    if ($hasPassword && !$alreadyUnlocked) {
        Response::json(['link' => [
            'id' => $link['id'],
            'name' => $link['name'],
            'recipientName' => $link['recipient_name'],
            'hasPassword' => true,
            'unlocked' => false,
        ]]);
        return;
    }

    if (!$alreadyUnlocked) {
        Auth::loginShareLink($id);
    }

    $content = shared_links_collect_content($link);
    Response::json([
        'link' => shared_links_serialize($link, true),
        'folders' => $content['folders'],
        'files' => $content['files'],
    ]);
}

function shared_links_unlock(array $params): void
{
    $id = $params['id'];
    $link = shared_links_load_valid($id);

    // Bucketed by link + IP (not by link alone) so many legitimate recipients
    // guessing/typing a password for the SAME shared link from different
    // places don't lock each other out — only repeated wrong guesses from one
    // source do.
    $bucket = 'share_unlock:' . $id . ':' . RateLimiter::clientIp();
    RateLimiter::guard($bucket, 8, 900);

    $body = Response::body();
    $password = (string) ($body['password'] ?? '');
    // A password-less link has nothing to check here (the frontend never calls this
    // endpoint for one — shared_links_get() unlocks it directly — but a stray direct
    // call must not be unconditionally rejected just because there's no password set).
    if ($link['password_hash'] !== null && !Auth::verify($password, $link['password_hash'])) {
        RateLimiter::recordFailure($bucket);
        Response::error('Şifre yanlış.', 401);
    }
    RateLimiter::clear($bucket);

    Auth::loginShareLink($id);

    $content = shared_links_collect_content($link);
    Response::json([
        'link' => shared_links_serialize($link, true),
        'folders' => $content['folders'],
        'files' => $content['files'],
    ]);
}

return [
    ['POST', '#^/shared-links$#', 'shared_links_create'],
    ['GET', '#^/shared-links/by-customer/(?P<customerId>[a-zA-Z0-9_]+)$#', 'shared_links_get_by_customer'],
    ['GET', '#^/shared-links/status-by-customers$#', 'shared_links_status_all'],
    ['GET', '#^/shared-links/(?P<id>[a-zA-Z0-9_]+)$#', 'shared_links_get'],
    ['POST', '#^/shared-links/(?P<id>[a-zA-Z0-9_]+)/unlock$#', 'shared_links_unlock'],
    ['POST', '#^/shared-links/(?P<id>[a-zA-Z0-9_]+)/revoke$#', 'shared_links_revoke'],
    ['POST', '#^/shared-links/(?P<id>[a-zA-Z0-9_]+)/password$#', 'shared_links_update_password'],
];
