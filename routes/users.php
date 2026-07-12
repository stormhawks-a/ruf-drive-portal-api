<?php

function users_list(array $params): void
{
    Auth::requireRole(['ADMIN', 'EDITOR']);
    $rows = Db::query('SELECT id, name, email, username, role, avatar_url, folder_id, is_active, created_at FROM users ORDER BY created_at ASC');
    Response::json(['users' => $rows]);
}

/** Admin-only, single-user, on-demand — deliberately not bundled into users_list
    so every personnel record's password isn't sitting in one bulk response. */
function users_get_password(array $params): void
{
    Auth::requireRole('ADMIN');
    $id = $params['id'];
    $target = Db::queryOne('SELECT password_encrypted FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        Response::error('Kullanıcı bulunamadı.', 404);
    }
    $password = $target['password_encrypted'] !== null ? Crypto::decrypt($target['password_encrypted']) : null;
    Response::json(['password' => $password]);
}

function users_create(array $params): void
{
    $actor = Auth::requireRole('ADMIN');
    $body = Response::body();

    $name = trim((string) ($body['name'] ?? ''));
    $role = (string) ($body['role'] ?? '');
    $email = trim((string) ($body['email'] ?? '')) ?: null;
    $username = trim((string) ($body['username'] ?? '')) ?: null;

    if ($name === '' || !in_array($role, ['ADMIN', 'EDITOR', 'CUSTOMER'], true)) {
        Response::error('Ad ve geçerli bir rol zorunlu.', 422);
    }
    if ($role !== 'CUSTOMER' && $username === null) {
        Response::error('Personel hesapları için kullanıcı adı zorunlu.', 422);
    }

    $plainPassword = (string) ($body['password'] ?? '');
    $generatedPassword = null;
    if ($plainPassword === '') {
        $generatedPassword = bin2hex(random_bytes(5));
        $plainPassword = $generatedPassword;
    }

    $userId = Ids::generate('user');
    $folderId = null;

    $db = Db::conn();
    $db->beginTransaction();
    try {
        if ($role === 'CUSTOMER') {
            $folderId = Ids::generate('folder');
            Db::execute('INSERT INTO folders (id, name, parent_id) VALUES (?, ?, NULL)', [$folderId, $name]);
        }

        Db::execute(
            'INSERT INTO users (id, name, email, username, password_hash, password_encrypted, role, folder_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $name, $email, $username, Auth::hash($plainPassword), Crypto::encrypt($plainPassword), $role, $folderId]
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            Response::error('Bu e-posta veya kullanıcı adı zaten kullanımda.', 409);
        }
        throw $e;
    }

    // Best-effort Drive mirror for the customer's root folder — without this, this
    // folder (and everything ever uploaded inside it) would never get a
    // drive_folder_id, silently breaking Drive sync for the whole customer.
    if ($folderId !== null) {
        try {
            $driveParentId = folders_resolve_drive_parent(null);
            if ($driveParentId !== null) {
                $driveFolderId = GoogleDriveClient::createFolder($name, $driveParentId);
                Db::execute('UPDATE folders SET drive_folder_id = ? WHERE id = ?', [$driveFolderId, $folderId]);
            }
        } catch (Throwable $e) {
            error_log('Drive klasor aynalama basarisiz (musteri kok klasoru): ' . $e->getMessage());
        }
    }

    AuditLogger::log($actor['id'], $actor['name'], $actor['role'], 'PERMISSION_CHANGE', "Yeni kullanıcı oluşturuldu: {$name} ({$role})");

    $response = ['id' => $userId, 'name' => $name, 'role' => $role, 'folderId' => $folderId];
    if ($generatedPassword !== null) {
        // Sadece bu ilk yanitta gosterilir; bir daha asla geri okunamaz.
        $response['generatedPassword'] = $generatedPassword;
    }
    Response::json($response, 201);
}

function users_update(array $params): void
{
    $actor = Auth::requireRole('ADMIN');
    $id = $params['id'];
    $body = Response::body();

    $target = Db::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        Response::error('Kullanıcı bulunamadı.', 404);
    }

    $fields = [];
    $values = [];
    foreach (['name', 'email', 'username'] as $col) {
        if (array_key_exists($col, $body)) {
            $fields[] = "{$col} = ?";
            $values[] = $body[$col];
        }
    }
    if (array_key_exists('role', $body) && in_array($body['role'], ['ADMIN', 'EDITOR', 'CUSTOMER'], true)) {
        $fields[] = 'role = ?';
        $values[] = $body['role'];
    }
    if (array_key_exists('isActive', $body)) {
        $fields[] = 'is_active = ?';
        $values[] = $body['isActive'] ? 1 : 0;
    }
    if (!empty($body['password'])) {
        $fields[] = 'password_hash = ?';
        $values[] = Auth::hash((string) $body['password']);
        $fields[] = 'password_encrypted = ?';
        $values[] = Crypto::encrypt((string) $body['password']);
    }

    if (empty($fields)) {
        Response::error('Güncellenecek alan gönderilmedi.', 422);
    }

    $values[] = $id;
    Db::execute('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);
    AuditLogger::log($actor['id'], $actor['name'], $actor['role'], 'PERMISSION_CHANGE', "Kullanıcı güncellendi: {$target['name']}");
    Response::json(['ok' => true]);
}

function users_delete(array $params): void
{
    $actor = Auth::requireRole('ADMIN');
    $id = $params['id'];

    if ($id === $actor['id']) {
        Response::error('Kendi hesabınızı silemezsiniz.', 400);
    }

    $target = Db::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        Response::error('Kullanıcı bulunamadı.', 404);
    }

    // A plain DELETE FROM users used to fail with an uncaught FK violation (surfaced
    // to the client as a generic "Sunucu hatası") the moment this user had ANY shared
    // link or uploaded file pointing at them — which, for a customer with real usage
    // history, is always. Clean up everything that references this user first.

    // shared_links.customer_user_id / created_by_id both have NO ACTION delete rules.
    Db::execute('DELETE FROM shared_links WHERE customer_user_id = ? OR created_by_id = ?', [$id, $id]);

    if ($target['folder_id'] !== null) {
        // Best-effort: the customer's Drive folder may already be gone (e.g. deleted
        // by hand from Drive directly), so a 404 here must not block the DB cleanup.
        $folder = Db::queryOne('SELECT drive_folder_id FROM folders WHERE id = ?', [$target['folder_id']]);
        if ($folder !== null && $folder['drive_folder_id'] !== null) {
            try {
                GoogleDriveClient::deleteFile($folder['drive_folder_id']);
            } catch (Throwable $e) {
                error_log('Drive klasörü silinemedi (müşteri silme): ' . $e->getMessage());
            }
        }
        // A plain `DELETE FROM folders WHERE id = ?` relies on the self-referential
        // parent_id FK's ON DELETE CASCADE to reach every descendant — which, once the
        // tree has even modest depth/branching, hits InnoDB's internal cascade
        // recursion limit ("Foreign key cascade delete/update exceeds max tables limit
        // of 30", MySQL error 6575) and fails with a 500. MySQL evaluates the cascade
        // per matched row regardless of whether the children are also in the same
        // DELETE's id list, so even a single multi-id statement still recurses. We've
        // already collected and are deleting the complete, self-contained set (every
        // descendant folder plus every file inside them), so it's safe to disable FK
        // checks for just these two statements instead of fighting the recursion limit.
        $allFolderIds = folders_collect_descendant_ids($target['folder_id']);
        $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));
        Db::execute('SET FOREIGN_KEY_CHECKS=0');
        try {
            Db::execute("DELETE FROM files WHERE parent_id IN ($placeholders)", $allFolderIds);
            Db::execute("DELETE FROM folders WHERE id IN ($placeholders)", $allFolderIds);
        } finally {
            Db::execute('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // Safety net for any files owned by this user outside their own folder tree
    // (shouldn't normally happen, but the FK would otherwise still block deletion).
    Db::execute('DELETE FROM files WHERE owner_id = ?', [$id]);

    Db::execute('DELETE FROM users WHERE id = ?', [$id]);
    AuditLogger::log($actor['id'], $actor['name'], $actor['role'], 'PERMISSION_CHANGE', "Kullanıcı silindi: {$target['name']}");
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/users$#', 'users_list'],
    ['POST', '#^/users$#', 'users_create'],
    ['PUT', '#^/users/(?P<id>[a-zA-Z0-9_]+)$#', 'users_update'],
    ['DELETE', '#^/users/(?P<id>[a-zA-Z0-9_]+)$#', 'users_delete'],
    ['GET', '#^/users/(?P<id>[a-zA-Z0-9_]+)/password$#', 'users_get_password'],
];
