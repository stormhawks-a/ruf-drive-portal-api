<?php

/** Lazily creates (once) a dedicated Drive folder for background media, caching its id
    in app_settings so every upload after the first reuses the same folder. */
function background_settings_resolve_drive_folder(): ?string
{
    $setting = Db::queryOne('SELECT value FROM app_settings WHERE `key` = ?', ['background_media_drive_folder_id']);
    if ($setting !== null && $setting['value'] !== null) {
        return $setting['value'];
    }

    $rootSetting = Db::queryOne('SELECT value FROM app_settings WHERE `key` = ?', ['drive_root_folder_id']);
    $rootId = $rootSetting['value'] ?? null;

    try {
        $folderId = GoogleDriveClient::createFolder('Site Arkaplanlari', $rootId);
    } catch (Throwable $e) {
        error_log('Arkaplan Drive klasoru olusturulamadi: ' . $e->getMessage());
        return null;
    }

    Db::execute(
        'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        ['background_media_drive_folder_id', $folderId]
    );
    return $folderId;
}

// Fixed number of collage photo slots — smallest/frontmost (index 0) to
// largest/backmost (index 11). A slot with no photo yet still "exists" (the
// frontend always renders all 12), so collageImages is a sparse array with
// nulls, not a compact list — index IS the slot, not just an upload order.
const COLLAGE_SLOT_COUNT = 12;

/** A background row plus its collage children, shaped for the frontend (media as our
    own streaming proxy urls — drive_file_id is never sent to the client). */
function background_settings_serialize(array $row): array
{
    $collageRows = Db::query(
        'SELECT * FROM background_collage_images WHERE background_settings_id = ?',
        [$row['id']]
    );
    $collageImages = array_fill(0, COLLAGE_SLOT_COUNT, null);
    foreach ($collageRows as $c) {
        $slot = (int) $c['sort_order'];
        if ($slot >= 0 && $slot < COLLAGE_SLOT_COUNT) {
            $collageImages[$slot] = "/background-settings/collage/{$c['id']}";
        }
    }

    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'type' => $row['type'],
        'url1' => $row['drive_file_id_1'] ? "/background-settings/{$row['id']}/media/1" : '',
        'url2' => $row['drive_file_id_2'] ? "/background-settings/{$row['id']}/media/2" : '',
        'sliderPosition' => $row['slider_position'] !== null ? (int) $row['slider_position'] : null,
        'collageImages' => $collageImages,
        'collageColors' => $row['collage_colors'] ? explode(',', $row['collage_colors']) : ['#ffe4ec', '#dbeafe', '#fef9c3'],
        'collageDistribution' => $row['collage_distribution'] !== null ? (int) $row['collage_distribution'] : 35,
        'collageMinSize' => $row['collage_min_size'] !== null ? (int) $row['collage_min_size'] : 34,
        'collageMaxSize' => $row['collage_max_size'] !== null ? (int) $row['collage_max_size'] : 150,
        'collageMinSensitivity' => $row['collage_min_sensitivity'] !== null ? (int) $row['collage_min_sensitivity'] : 5,
        'collageMaxSensitivity' => $row['collage_max_sensitivity'] !== null ? (int) $row['collage_max_sensitivity'] : 85,
        'collageScale' => $row['collage_scale'] !== null ? (int) $row['collage_scale'] : 100,
        'collageSpread' => $row['collage_spread'] !== null ? (int) $row['collage_spread'] : 65,
        'collageHeadlineText' => $row['collage_headline_text'] ?? '',
        'collageHeadlineFont' => $row['collage_headline_font'] ?: "'Iowan Old Style','Palatino Linotype',Georgia,serif",
        'collageHeadlineColor' => $row['collage_headline_color'] ?: '#1c1f2a',
        'collageHeadlineSize' => $row['collage_headline_size'] !== null ? (int) $row['collage_headline_size'] : 36,
        'title' => $row['title'],
        'subtitle' => $row['subtitle'],
        'ctaEnabled' => (bool) $row['cta_enabled'],
        'ctaStyle' => $row['cta_style'],
        'ctaLabel' => $row['cta_label'],
        'ctaUrl' => $row['cta_url'],
        'sortOrder' => (int) $row['sort_order'],
    ];
}

/** Every route below needs "any real identity" — staff managing them in Settings,
    a real customer login, or an anonymous-but-valid share-link visitor — since the
    background is shown across all three contexts. Mirrors files_download's dual path. */
function background_settings_require_viewer(): void
{
    if (Auth::currentUser() !== null) {
        return;
    }
    if (Auth::currentShareLinkId() !== null) {
        return;
    }
    Response::error('Oturum açmanız gerekiyor.', 401);
}

function background_settings_list(array $params): void
{
    background_settings_require_viewer();
    $rows = Db::query('SELECT * FROM background_settings ORDER BY sort_order ASC, created_at ASC');
    Response::json(['backgrounds' => array_map('background_settings_serialize', $rows)]);
}

function background_settings_create(array $params): void
{
    Auth::requireRole('ADMIN');
    $body = Response::body();
    $name = trim((string) ($body['name'] ?? ''));
    $type = (string) ($body['type'] ?? '');
    if ($name === '' || !in_array($type, ['image', 'video', 'slider', 'collage'], true)) {
        Response::error('Ad ve geçerli bir tür zorunlu.', 422);
    }

    $id = Ids::generate('bg');
    $maxSort = Db::queryOne('SELECT MAX(sort_order) as m FROM background_settings');
    $sortOrder = ((int) ($maxSort['m'] ?? 0)) + 1;

    Db::execute(
        'INSERT INTO background_settings (id, name, type, slider_position, sort_order) VALUES (?, ?, ?, ?, ?)',
        [$id, $name, $type, $type === 'slider' ? 50 : null, $sortOrder]
    );

    $row = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    Response::json(['background' => background_settings_serialize($row)], 201);
}

function background_settings_update(array $params): void
{
    Auth::requireRole('ADMIN');
    $id = $params['id'];
    $row = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    if ($row === null) {
        Response::error('Arkaplan bulunamadı.', 404);
    }

    $body = Response::body();
    $map = [
        'name' => 'name',
        'title' => 'title',
        'subtitle' => 'subtitle',
        'ctaLabel' => 'cta_label',
        'ctaUrl' => 'cta_url',
        'sortOrder' => 'sort_order',
    ];
    $fields = [];
    $values = [];
    foreach ($map as $bodyKey => $col) {
        if (array_key_exists($bodyKey, $body)) {
            $fields[] = "{$col} = ?";
            $values[] = $body[$bodyKey];
        }
    }
    if (array_key_exists('ctaEnabled', $body)) {
        $fields[] = 'cta_enabled = ?';
        $values[] = $body['ctaEnabled'] ? 1 : 0;
    }
    if (array_key_exists('ctaStyle', $body) && in_array($body['ctaStyle'], ['cursor', 'fixed'], true)) {
        $fields[] = 'cta_style = ?';
        $values[] = $body['ctaStyle'];
    }
    if (array_key_exists('sliderPosition', $body)) {
        $fields[] = 'slider_position = ?';
        $values[] = max(0, min(100, (int) $body['sliderPosition']));
    }

    // Kolaj-ozel ayarlar — sadece type='collage' icin anlamli ama herhangi bir
    // arkaplan turunde gonderilse de zararsizdir (baska yerde hic okunmaz).
    if (array_key_exists('collageColors', $body) && is_array($body['collageColors'])) {
        $colors = array_values(array_filter(
            $body['collageColors'],
            fn($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)
        ));
        $colors = array_slice($colors, 0, 6);
        if (count($colors) >= 2) {
            $fields[] = 'collage_colors = ?';
            $values[] = implode(',', $colors);
        }
    }
    if (array_key_exists('collageDistribution', $body)) {
        $fields[] = 'collage_distribution = ?';
        $values[] = max(0, min(100, (int) $body['collageDistribution']));
    }
    if (array_key_exists('collageMinSize', $body)) {
        $fields[] = 'collage_min_size = ?';
        $values[] = max(10, min(300, (int) $body['collageMinSize']));
    }
    if (array_key_exists('collageMaxSize', $body)) {
        $fields[] = 'collage_max_size = ?';
        $values[] = max(10, min(700, (int) $body['collageMaxSize']));
    }
    if (array_key_exists('collageMinSensitivity', $body)) {
        $fields[] = 'collage_min_sensitivity = ?';
        $values[] = max(0, min(100, (int) $body['collageMinSensitivity']));
    }
    if (array_key_exists('collageMaxSensitivity', $body)) {
        $fields[] = 'collage_max_sensitivity = ?';
        $values[] = max(0, min(100, (int) $body['collageMaxSensitivity']));
    }
    if (array_key_exists('collageScale', $body)) {
        $fields[] = 'collage_scale = ?';
        $values[] = max(25, min(400, (int) $body['collageScale']));
    }
    if (array_key_exists('collageSpread', $body)) {
        $fields[] = 'collage_spread = ?';
        $values[] = max(0, min(100, (int) $body['collageSpread']));
    }
    if (array_key_exists('collageHeadlineText', $body)) {
        $fields[] = 'collage_headline_text = ?';
        $values[] = (string) $body['collageHeadlineText'];
    }
    if (array_key_exists('collageHeadlineFont', $body)) {
        $fields[] = 'collage_headline_font = ?';
        $values[] = (string) $body['collageHeadlineFont'];
    }
    if (array_key_exists('collageHeadlineColor', $body) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $body['collageHeadlineColor'])) {
        $fields[] = 'collage_headline_color = ?';
        $values[] = $body['collageHeadlineColor'];
    }
    if (array_key_exists('collageHeadlineSize', $body)) {
        $fields[] = 'collage_headline_size = ?';
        $values[] = max(10, min(120, (int) $body['collageHeadlineSize']));
    }

    if (empty($fields)) {
        Response::error('Güncellenecek alan gönderilmedi.', 422);
    }

    $values[] = $id;
    Db::execute('UPDATE background_settings SET ' . implode(', ', $fields) . ' WHERE id = ?', $values);
    $updated = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    Response::json(['background' => background_settings_serialize($updated)]);
}

function background_settings_delete(array $params): void
{
    Auth::requireRole('ADMIN');
    $id = $params['id'];
    $row = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    if ($row === null) {
        Response::error('Arkaplan bulunamadı.', 404);
    }

    $collageRows = Db::query('SELECT * FROM background_collage_images WHERE background_settings_id = ?', [$id]);
    foreach ($collageRows as $c) {
        try {
            GoogleDriveClient::deleteFile($c['drive_file_id']);
        } catch (Throwable $e) {
            error_log('Kolaj gorseli Drive\'dan silinemedi: ' . $e->getMessage());
        }
    }
    foreach ([$row['drive_file_id_1'], $row['drive_file_id_2']] as $driveFileId) {
        if ($driveFileId !== null) {
            try {
                GoogleDriveClient::deleteFile($driveFileId);
            } catch (Throwable $e) {
                error_log('Arkaplan medyasi Drive\'dan silinemedi: ' . $e->getMessage());
            }
        }
    }

    // shared_link_folders-style cascade isn't needed here: collage rows have their own
    // ON DELETE CASCADE FK against background_settings.
    Db::execute('DELETE FROM background_settings WHERE id = ?', [$id]);
    Response::json(['ok' => true]);
}

/** Shared upload handler for both the url1/url2 slots and a new collage photo — reads
    the uploaded file, pushes it to Drive under the dedicated backgrounds folder.
    Returns both the new Drive file id and its mime type — the latter has to be stored
    too, since background_settings_stream_media/_collage need it to send a correct
    Content-Type header (without one, browsers refuse to play <video>, unlike <img>
    which sniffs the bytes and mostly gets away without it). */
function background_settings_store_upload(): array
{
    // Videos in particular can take a while to reach Drive over this server's own
    // connection — don't let PHP's default ~30s execution limit kill it mid-upload.
    @set_time_limit(0);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        Response::error('Dosya yüklenemedi.', 422);
    }
    $uploaded = $_FILES['file'];
    $mimeType = (string) ($uploaded['type'] ?: 'application/octet-stream');
    $contents = file_get_contents($uploaded['tmp_name']);

    $driveParentId = background_settings_resolve_drive_folder();
    if ($driveParentId === null) {
        Response::error('Drive bağlantısı kurulamadı, tekrar deneyin.', 502);
    }

    $driveFileId = GoogleDriveClient::uploadFile($uploaded['name'], $driveParentId, $mimeType, $contents);
    return ['driveFileId' => $driveFileId, 'mimeType' => $mimeType];
}

function background_settings_upload_media(array $params): void
{
    Auth::requireRole('ADMIN');
    $id = $params['id'];
    $slot = $params['slot'];
    if (!in_array($slot, ['1', '2'], true)) {
        Response::error('Geçersiz slot.', 422);
    }
    $row = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    if ($row === null) {
        Response::error('Arkaplan bulunamadı.', 404);
    }

    $upload = background_settings_store_upload();
    $driveFileId = $upload['driveFileId'];

    $column = $slot === '1' ? 'drive_file_id_1' : 'drive_file_id_2';
    $mimeColumn = $slot === '1' ? 'media1_mime_type' : 'media2_mime_type';
    $oldDriveFileId = $row[$column];
    Db::execute("UPDATE background_settings SET {$column} = ?, {$mimeColumn} = ? WHERE id = ?", [$driveFileId, $upload['mimeType'], $id]);
    if ($oldDriveFileId !== null) {
        try {
            GoogleDriveClient::deleteFile($oldDriveFileId);
        } catch (Throwable $e) {
            error_log('Eski arkaplan medyasi Drive\'dan silinemedi: ' . $e->getMessage());
        }
    }

    $updated = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    Response::json(['background' => background_settings_serialize($updated)]);
}

/** Uploads into one specific fixed slot (0-11, smallest/front to largest/back) —
    not an append. Uploading again into an already-filled slot replaces whatever
    was there (old Drive file deleted), rather than adding a new photo, since the
    slot IS the identity here, not an auto-incrementing position. */
function background_settings_add_collage(array $params): void
{
    Auth::requireRole('ADMIN');
    $id = $params['id'];
    $row = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    if ($row === null) {
        Response::error('Arkaplan bulunamadı.', 404);
    }

    $slotIndex = isset($_POST['slotIndex']) ? (int) $_POST['slotIndex'] : -1;
    if ($slotIndex < 0 || $slotIndex >= COLLAGE_SLOT_COUNT) {
        Response::error('Geçersiz fotoğraf yuvası.', 422);
    }

    $upload = background_settings_store_upload();

    $existing = Db::queryOne(
        'SELECT * FROM background_collage_images WHERE background_settings_id = ? AND sort_order = ?',
        [$id, $slotIndex]
    );
    if ($existing !== null) {
        try {
            GoogleDriveClient::deleteFile($existing['drive_file_id']);
        } catch (Throwable $e) {
            error_log('Eski kolaj gorseli Drive\'dan silinemedi: ' . $e->getMessage());
        }
        Db::execute('DELETE FROM background_collage_images WHERE id = ?', [$existing['id']]);
    }

    Db::execute(
        'INSERT INTO background_collage_images (background_settings_id, drive_file_id, mime_type, sort_order) VALUES (?, ?, ?, ?)',
        [$id, $upload['driveFileId'], $upload['mimeType'], $slotIndex]
    );

    $updated = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    Response::json(['background' => background_settings_serialize($updated)], 201);
}

function background_settings_delete_collage(array $params): void
{
    Auth::requireRole('ADMIN');
    $collageId = $params['collageId'];
    $row = Db::queryOne('SELECT * FROM background_collage_images WHERE id = ?', [$collageId]);
    if ($row === null) {
        Response::error('Fotoğraf bulunamadı.', 404);
    }

    try {
        GoogleDriveClient::deleteFile($row['drive_file_id']);
    } catch (Throwable $e) {
        error_log('Kolaj gorseli Drive\'dan silinemedi: ' . $e->getMessage());
    }
    Db::execute('DELETE FROM background_collage_images WHERE id = ?', [$collageId]);
    Response::json(['ok' => true]);
}

function background_settings_stream_media(array $params): void
{
    background_settings_require_viewer();
    $id = $params['id'];
    $slot = $params['slot'];
    $row = Db::queryOne('SELECT * FROM background_settings WHERE id = ?', [$id]);
    if ($row === null) {
        Response::error('Arkaplan bulunamadı.', 404);
    }
    $driveFileId = $slot === '1' ? $row['drive_file_id_1'] : $row['drive_file_id_2'];
    $mimeType = $slot === '1' ? $row['media1_mime_type'] : $row['media2_mime_type'];
    if ($driveFileId === null) {
        Response::error('Medya bulunamadı.', 404);
    }

    // jpg/png backgrounds (single images and both slider sides) are shown full-bleed
    // on screen, never downloaded — Drive's pre-generated thumbnail at a large size
    // loads far faster than the original and looks identical there. Videos have no
    // such thumbnail to play, so they always stream the original bytes.
    if (in_array($mimeType, ['image/jpeg', 'image/png'], true) && GoogleDriveClient::streamThumbnail($driveFileId, 1920)) {
        exit;
    }

    @set_time_limit(0);
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=3600');

    // Safari's <video> element refuses to play at all unless the server answers
    // Range requests with a real 206 — Chrome/Firefox tolerate a single 200 with
    // the whole body, Safari doesn't. files_download already implements this
    // (files_parse_range_header, loaded globally since index.php requires every
    // routes/*.php file regardless of which one matches); reuse it here instead
    // of duplicating the parsing logic.
    $totalSize = GoogleDriveClient::getFileSize($driveFileId);
    $range = files_parse_range_header($_SERVER['HTTP_RANGE'] ?? null, $totalSize);
    if ($range !== null) {
        [$start, $end] = $range;
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$totalSize}");
        header('Content-Length: ' . ($end - $start + 1));
        GoogleDriveClient::streamFileTo($driveFileId, function (string $chunk): void {
            echo $chunk;
        }, "bytes={$start}-{$end}");
        exit;
    }

    if ($totalSize > 0) {
        header('Content-Length: ' . $totalSize);
    }
    GoogleDriveClient::streamFile($driveFileId);
    exit;
}

function background_settings_stream_collage(array $params): void
{
    background_settings_require_viewer();
    $collageId = $params['collageId'];
    $row = Db::queryOne('SELECT * FROM background_collage_images WHERE id = ?', [$collageId]);
    if ($row === null) {
        Response::error('Fotoğraf bulunamadı.', 404);
    }

    // Collage photos are always images — same large pre-generated Drive thumbnail
    // speedup as background_settings_stream_media above.
    if (in_array($row['mime_type'], ['image/jpeg', 'image/png'], true) && GoogleDriveClient::streamThumbnail($row['drive_file_id'], 1920)) {
        exit;
    }

    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=3600');
    GoogleDriveClient::streamFile($row['drive_file_id']);
    exit;
}

return [
    ['GET', '#^/background-settings$#', 'background_settings_list'],
    ['POST', '#^/background-settings$#', 'background_settings_create'],
    ['PUT', '#^/background-settings/(?P<id>[a-zA-Z0-9_]+)$#', 'background_settings_update'],
    ['DELETE', '#^/background-settings/(?P<id>[a-zA-Z0-9_]+)$#', 'background_settings_delete'],
    ['POST', '#^/background-settings/(?P<id>[a-zA-Z0-9_]+)/media/(?P<slot>[12])$#', 'background_settings_upload_media'],
    ['GET', '#^/background-settings/(?P<id>[a-zA-Z0-9_]+)/media/(?P<slot>[12])$#', 'background_settings_stream_media'],
    ['POST', '#^/background-settings/(?P<id>[a-zA-Z0-9_]+)/collage$#', 'background_settings_add_collage'],
    ['GET', '#^/background-settings/collage/(?P<collageId>[0-9]+)$#', 'background_settings_stream_collage'],
    ['DELETE', '#^/background-settings/collage/(?P<collageId>[0-9]+)$#', 'background_settings_delete_collage'],
];
