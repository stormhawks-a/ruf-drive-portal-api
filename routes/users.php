<?php

function users_list(array $params): void
{
    Auth::requireRole(['ADMIN', 'EDITOR']);
    $rows = Db::query('SELECT id, name, email, username, role, avatar_url, folder_id, is_active, created_at FROM users ORDER BY created_at ASC');
    Response::json(['users' => $rows]);
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
            'INSERT INTO users (id, name, email, username, password_hash, role, folder_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $name, $email, $username, Auth::hash($plainPassword), $role, $folderId]
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            Response::error('Bu e-posta veya kullanıcı adı zaten kullanımda.', 409);
        }
        throw $e;
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

    Db::execute('DELETE FROM users WHERE id = ?', [$id]);
    AuditLogger::log($actor['id'], $actor['name'], $actor['role'], 'PERMISSION_CHANGE', "Kullanıcı silindi: {$target['name']}");
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/users$#', 'users_list'],
    ['POST', '#^/users$#', 'users_create'],
    ['PUT', '#^/users/(?P<id>[a-zA-Z0-9_]+)$#', 'users_update'],
    ['DELETE', '#^/users/(?P<id>[a-zA-Z0-9_]+)$#', 'users_delete'],
];
