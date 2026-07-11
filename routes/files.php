<?php

// Not: Bu asamada (Faz 1) dosya baytlari henuz Drive'a gitmiyor; sadece
// metadata kaydediliyor. Gercek yukleme/indirme Faz 3'te GoogleDriveClient
// ile bu route'lara eklenecek.

function files_list(array $params): void
{
    $user = Auth::requireAuth();
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
    $body = Response::body();

    $name = trim((string) ($body['name'] ?? ''));
    $parentId = $body['parentId'] ?? null;
    $fileType = (string) ($body['fileType'] ?? 'other');
    $sizeBytes = (int) ($body['sizeBytes'] ?? 0);
    $mimeType = $body['mimeType'] ?? null;

    if ($name === '' || $parentId === null) {
        Response::error('Dosya adı ve klasör zorunlu.', 422);
    }
    Scope::assertFolderAccessible($user, $parentId);

    $allowedTypes = ['pdf', 'image', 'video', 'audio', 'doc', 'sheet', 'other'];
    if (!in_array($fileType, $allowedTypes, true)) {
        $fileType = 'other';
    }

    $id = Ids::generate('file');
    Db::execute(
        'INSERT INTO files (id, name, original_name, size_bytes, mime_type, file_type, parent_id, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $name, $name, $sizeBytes, $mimeType, $fileType, $parentId, $user['id']]
    );
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'FILE_UPLOAD', "Dosya eklendi: {$name}");

    $row = Db::queryOne('SELECT * FROM files WHERE id = ?', [$id]);
    Response::json(['file' => $row], 201);
}

function files_update(array $params): void
{
    $user = Auth::requireAuth();
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
    $user = Auth::requireAuth();
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
    Auth::requireRole(['ADMIN', 'EDITOR']);
    $id = $params['id'];
    Db::execute('UPDATE files SET deleted_at = NULL, deleted_by = NULL WHERE id = ?', [$id]);
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/files$#', 'files_list'],
    ['POST', '#^/files$#', 'files_create'],
    ['PUT', '#^/files/(?P<id>[a-zA-Z0-9_]+)$#', 'files_update'],
    ['DELETE', '#^/files/(?P<id>[a-zA-Z0-9_]+)$#', 'files_delete'],
    ['POST', '#^/files/(?P<id>[a-zA-Z0-9_]+)/restore$#', 'files_restore'],
];
