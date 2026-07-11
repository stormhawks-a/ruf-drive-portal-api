<?php

// Tek seferlik: Google Drive baglantisi kurulmadan ONCE olusturulmus klasorleri
// geriye donuk olarak Drive'da olusturup drive_folder_id'lerini kaydeder.
// Guvenle tekrar calistirilabilir (zaten eslenmis klasorleri atlar).

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

function backfill_resolve_drive_parent(?string $parentId): ?string
{
    if ($parentId !== null) {
        $parent = Db::queryOne('SELECT drive_folder_id FROM folders WHERE id = ?', [$parentId]);
        return $parent['drive_folder_id'] ?? null;
    }
    $setting = Db::queryOne('SELECT value FROM app_settings WHERE `key` = ?', ['drive_root_folder_id']);
    return $setting['value'] ?? null;
}

$processed = 0;
$failed = 0;

// Process level by level (top-level folders first) so each folder's parent already
// has a drive_folder_id by the time we get to it.
for ($guard = 0; $guard < 20; $guard++) {
    $pending = Db::query(
        'SELECT * FROM folders WHERE drive_folder_id IS NULL
         AND (parent_id IS NULL OR parent_id IN (SELECT id FROM folders WHERE drive_folder_id IS NOT NULL))
         ORDER BY created_at ASC'
    );
    if (empty($pending)) {
        break;
    }

    foreach ($pending as $folder) {
        $driveParentId = backfill_resolve_drive_parent($folder['parent_id']);
        if ($driveParentId === null) {
            echo "Atlandi (ust klasor henuz eslenmedi): {$folder['name']} ({$folder['id']})\n";
            $failed++;
            continue;
        }
        try {
            $driveFolderId = GoogleDriveClient::createFolder($folder['name'], $driveParentId);
            Db::execute('UPDATE folders SET drive_folder_id = ? WHERE id = ?', [$driveFolderId, $folder['id']]);
            echo "Eslendi: {$folder['name']} ({$folder['id']}) -> Drive {$driveFolderId}\n";
            $processed++;
        } catch (Throwable $e) {
            echo "HATA ({$folder['name']}): " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

echo "\nBitti. Eslenen: {$processed}, atlanan/hatali: {$failed}\n";
