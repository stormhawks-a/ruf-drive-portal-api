<?php

/**
 * Social media links shown under the credit line on the customer/consumer
 * panels — a single global object (not per-background, unlike everything in
 * background_settings.php), stored as one JSON blob in app_settings under
 * the key 'social_links'. Each platform is independently enabled/disabled
 * and carries its own URL, so a platform with no URL set (or disabled) is
 * simply omitted from what the frontend renders.
 */

const SOCIAL_LINKS_PLATFORMS = ['linkedin', 'instagram', 'behance', 'youtube', 'twitter', 'tiktok'];

function social_links_defaults(): array
{
    $out = [];
    foreach (SOCIAL_LINKS_PLATFORMS as $platform) {
        $out[$platform] = ['enabled' => false, 'url' => ''];
    }
    return $out;
}

/** Same dual-path as background_settings_require_viewer: staff managing this in
    Settings, a real customer login, or an anonymous-but-valid share-link visitor
    all need to be able to read this, since it's shown across all three contexts. */
function social_links_require_viewer(): void
{
    if (Auth::currentUser() !== null) {
        return;
    }
    if (Auth::currentShareLinkId() !== null) {
        return;
    }
    Response::error('Oturum açmanız gerekiyor.', 401);
}

function social_links_load(): array
{
    $row = Db::queryOne('SELECT value FROM app_settings WHERE `key` = ?', ['social_links']);
    $decoded = $row && $row['value'] ? json_decode($row['value'], true) : null;
    $links = social_links_defaults();
    if (is_array($decoded)) {
        foreach (SOCIAL_LINKS_PLATFORMS as $platform) {
            if (isset($decoded[$platform]) && is_array($decoded[$platform])) {
                $links[$platform] = [
                    'enabled' => (bool) ($decoded[$platform]['enabled'] ?? false),
                    'url' => (string) ($decoded[$platform]['url'] ?? ''),
                ];
            }
        }
    }
    return $links;
}

function social_links_get(array $params): void
{
    social_links_require_viewer();
    Response::json(['socialLinks' => social_links_load()]);
}

function social_links_update(array $params): void
{
    Auth::requireRole('ADMIN');
    $body = Response::body();
    $links = social_links_load();

    foreach (SOCIAL_LINKS_PLATFORMS as $platform) {
        if (!isset($body[$platform]) || !is_array($body[$platform])) {
            continue;
        }
        if (array_key_exists('enabled', $body[$platform])) {
            $links[$platform]['enabled'] = (bool) $body[$platform]['enabled'];
        }
        if (array_key_exists('url', $body[$platform])) {
            $links[$platform]['url'] = trim((string) $body[$platform]['url']);
        }
    }

    Db::execute(
        'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        ['social_links', json_encode($links)]
    );

    Response::json(['socialLinks' => $links]);
}

return [
    ['GET', '#^/social-links$#', 'social_links_get'],
    ['PUT', '#^/social-links$#', 'social_links_update'],
];
