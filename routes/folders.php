<?php

function folders_list(array $params): void
{
    $user = Auth::requireAuth();
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

    $row = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    Response::json(['folder' => $row], 201);
}

function folders_update(array $params): void
{
    $user = Auth::requireAuth();
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
    $user = Auth::requireAuth();
    $id = $params['id'];
    $folder = Db::queryOne('SELECT * FROM folders WHERE id = ?', [$id]);
    if ($folder === null) {
        Response::error('Klasör bulunamadı.', 404);
    }
    Scope::assertFolderAccessible($user, $id);

    Db::execute('UPDATE folders SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$user['id'], $id]);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_DELETE', "Klasör çöp kutusuna taşındı: {$folder['name']}");
    Response::json(['ok' => true]);
}

function folders_restore(array $params): void
{
    $user = Auth::requireRole(['ADMIN', 'EDITOR']);
    $id = $params['id'];
    Db::execute('UPDATE folders SET deleted_at = NULL, deleted_by = NULL WHERE id = ?', [$id]);
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/folders$#', 'folders_list'],
    ['POST', '#^/folders$#', 'folders_create'],
    ['PUT', '#^/folders/(?P<id>[a-zA-Z0-9_]+)$#', 'folders_update'],
    ['DELETE', '#^/folders/(?P<id>[a-zA-Z0-9_]+)$#', 'folders_delete'],
    ['POST', '#^/folders/(?P<id>[a-zA-Z0-9_]+)/restore$#', 'folders_restore'],
];
