<?php

/**
 * Temporary, admin-only diagnostic — isolates WHY uploads are slow: is it (a)
 * Google Drive throttling each connection individually, in which case running
 * several uploads at once should multiply real throughput, or (b) this hosting
 * account's own outbound bandwidth being capped as a whole, in which case no
 * amount of concurrency in our code can ever help.
 *
 * ?concurrent=1 (default) uploads one disposable blob directly to Drive from
 * this server — no browser round-trip, no chunk-loop overhead, just the raw
 * createResumableSession/uploadChunk calls the real upload path uses.
 *
 * ?concurrent=2 (or more) fires that many uploads at Drive AT THE SAME TIME
 * (via curl_multi, real parallel connections) and reports both the aggregate
 * MB/s across all of them and what each individual stream achieved:
 *   - If each stream still gets ~the same MB/s as the concurrent=1 case (so
 *     aggregate scales up with the count), Drive is throttling per connection
 *     — concurrency in the app is the right fix, and if it's not helping in
 *     practice, something in our own concurrency plumbing needs another look.
 *   - If the streams instead SPLIT one shared total (aggregate stays flat,
 *     each stream gets roughly total/concurrent), the hosting account's own
 *     outbound bandwidth is the ceiling — no client-side or server-side
 *     concurrency will ever get past that; only a hosting upgrade would.
 *
 * Delete this route once that's answered.
 */
function diagnostics_upload_speed_test(array $params): void
{
    Auth::requireRole('ADMIN');

    $sizeMb = isset($_GET['sizeMb']) ? max(1, min(100, (int) $_GET['sizeMb'])) : 20;
    $concurrent = isset($_GET['concurrent']) ? max(1, min(6, (int) $_GET['concurrent'])) : 1;
    $totalBytes = $sizeMb * 1024 * 1024;

    $driveParentId = folders_resolve_drive_parent(null);
    if ($driveParentId === null) {
        Response::error('Drive kök klasörü ayarlanmamış.', 500);
    }

    // Metadata/session-creation calls are cheap and not what we're timing —
    // do all of them up front, sequentially, so the timed section below is
    // purely the parallel byte-upload itself.
    $sessions = [];
    for ($i = 0; $i < $concurrent; $i++) {
        $name = 'ZZ-hiz-testi-' . date('Ymd-His') . '-' . $i . '.bin';
        $sessions[] = [
            'uri' => GoogleDriveClient::createResumableSession($name, $driveParentId, 'application/octet-stream', $totalBytes),
            'blob' => random_bytes($totalBytes),
        ];
    }

    $multiHandle = curl_multi_init();
    $handles = [];
    foreach ($sessions as $i => $session) {
        $ch = curl_init($session['uri']);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $session['blob'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_HTTPHEADER => [
                'Content-Range: bytes 0-' . ($totalBytes - 1) . '/' . $totalBytes,
                'Content-Length: ' . $totalBytes,
            ],
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$i] = $ch;
    }

    $t0 = microtime(true);
    $running = null;
    do {
        $status = curl_multi_exec($multiHandle, $running);
        if ($running > 0) {
            curl_multi_select($multiHandle);
        }
    } while ($running > 0 && $status === CURLM_OK);
    $totalSeconds = microtime(true) - $t0;

    $fileIds = [];
    $perStreamOk = [];
    foreach ($handles as $ch) {
        $response = curl_multi_getcontent($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr((string) $response, $headerSize);
        $data = json_decode($body, true);
        $ok = $httpStatus === 200 || $httpStatus === 201;
        $perStreamOk[] = $ok;
        if ($ok && !empty($data['id'])) {
            $fileIds[] = $data['id'];
        }
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    curl_multi_close($multiHandle);
    unset($sessions);

    foreach ($fileIds as $fileId) {
        try {
            GoogleDriveClient::deleteFile($fileId);
        } catch (Throwable $e) {
            error_log('Hiz testi dosyasi silinemedi: ' . $e->getMessage());
        }
    }

    $totalMb = $sizeMb * $concurrent;

    Response::json([
        'concurrent' => $concurrent,
        'sizeMbPerStream' => $sizeMb,
        'totalMbTransferred' => $totalMb,
        'totalSeconds' => round($totalSeconds, 2),
        'aggregateMbPerSecond' => $totalSeconds > 0 ? round($totalMb / $totalSeconds, 2) : null,
        'impliedPerStreamMbPerSecond' => $totalSeconds > 0 ? round($sizeMb / $totalSeconds, 2) : null,
        'allStreamsSucceeded' => !in_array(false, $perStreamOk, true),
    ]);
}

return [
    ['GET', '#^/diagnostics/upload-speed-test$#', 'diagnostics_upload_speed_test'],
];
