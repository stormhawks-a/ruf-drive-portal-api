<?php

/**
 * Temporary, admin-only diagnostic — isolates whether slow uploads are caused by
 * our chunking/relay code or by the hosting server's own outbound bandwidth to
 * Google Drive. It uploads a single disposable in-memory blob DIRECTLY to Drive
 * from this server (no browser round-trip, no chunk-loop overhead — the same
 * createResumableSession/uploadChunk calls the real upload path uses, just
 * invoked once with the whole blob as one "chunk") and reports the raw MB/s.
 *
 * If this reports roughly the same slow speed users see in the app, the
 * bottleneck is the hosting account's outbound bandwidth to Google, not
 * anything in this codebase. Delete this route once that's confirmed.
 */
function diagnostics_upload_speed_test(array $params): void
{
    Auth::requireRole('ADMIN');

    $sizeMb = isset($_GET['sizeMb']) ? max(1, min(100, (int) $_GET['sizeMb'])) : 20;
    $totalBytes = $sizeMb * 1024 * 1024;

    $driveParentId = folders_resolve_drive_parent(null);
    if ($driveParentId === null) {
        Response::error('Drive kök klasörü ayarlanmamış.', 500);
    }

    $blob = random_bytes($totalBytes);
    $name = 'ZZ-hiz-testi-' . date('Ymd-His') . '.bin';

    $t0 = microtime(true);
    $sessionUri = GoogleDriveClient::createResumableSession($name, $driveParentId, 'application/octet-stream', $totalBytes);
    $t1 = microtime(true);

    $result = GoogleDriveClient::uploadChunk($sessionUri, $blob, 0, $totalBytes);
    $t2 = microtime(true);
    unset($blob);

    // Disposable test file — clean up immediately regardless of outcome.
    if (!empty($result['fileId'])) {
        try {
            GoogleDriveClient::deleteFile($result['fileId']);
        } catch (Throwable $e) {
            error_log('Hiz testi dosyasi silinemedi: ' . $e->getMessage());
        }
    }

    $sessionSeconds = $t1 - $t0;
    $uploadSeconds = $t2 - $t1;

    Response::json([
        'sizeMb' => $sizeMb,
        'sessionCreateSeconds' => round($sessionSeconds, 2),
        'uploadSeconds' => round($uploadSeconds, 2),
        'mbPerSecond' => $uploadSeconds > 0 ? round($sizeMb / $uploadSeconds, 2) : null,
        'done' => $result['done'] ?? false,
    ]);
}

return [
    ['GET', '#^/diagnostics/upload-speed-test$#', 'diagnostics_upload_speed_test'],
];
