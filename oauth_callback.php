<?php

/**
 * Google's redirect target after the consent screen (see oauth_authorize.php
 * and config.php's google.redirect_uri, which must match this exact URL in
 * Google Cloud Console's "Authorized redirect URIs"). Exchanges the one-time
 * `code` for a refresh_token + access_token and persists them
 * (GoogleOAuth::handleCallback) — every Drive call afterward reads that
 * stored refresh_token to mint fresh access tokens, so this is the one place
 * that actually fixes an "invalid_grant / expired or revoked" outage.
 */

require __DIR__ . '/bootstrap.php';

Auth::requireRole('ADMIN');

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

header('Content-Type: text/plain; charset=utf-8');

if ($error !== null) {
    http_response_code(400);
    echo "Google yetkilendirmeyi reddetti: {$error}\n";
    exit;
}

if (!is_string($code) || $code === '') {
    http_response_code(400);
    echo "Eksik yetkilendirme kodu (code parametresi yok).\n";
    exit;
}

try {
    GoogleOAuth::handleCallback($code);
    echo "Google Drive yetkilendirmesi basarili. Bu sekmeyi kapatabilirsiniz.\n";
} catch (Throwable $e) {
    error_log('OAuth callback basarisiz: ' . $e->getMessage());
    http_response_code(500);
    echo "Yetkilendirme basarisiz oldu: {$e->getMessage()}\n";
}
