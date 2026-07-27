<?php

/**
 * One-time (or re-run-when-revoked) Google Drive authorization entry point.
 * ADMIN-only: visiting this redirects to Google's consent screen for the
 * ONE Google account this whole app stores files under — anyone able to hit
 * this unauthenticated could re-point the app's entire Drive storage at
 * their own account, so it's gated exactly like any other admin-only action.
 * On success Google redirects to oauth_callback.php, which actually saves
 * the refresh token (see GoogleOAuth::handleCallback).
 */

require __DIR__ . '/bootstrap.php';

Auth::requireRole('ADMIN');

header('Location: ' . GoogleOAuth::buildAuthUrl());
exit;
