<?php

declare(strict_types=1);

// PHP defaults to UTC with nothing set here, while MySQL's CURRENT_TIMESTAMP/NOW()
// follow the server's local timezone — any PHP-side date()/time() call would
// otherwise silently disagree with timestamps MySQL generates itself (see the
// deleted_at fix in routes/folders.php for a concrete case this caused).
date_default_timezone_set('Europe/Istanbul');

error_reporting(E_ALL);
ini_set('display_errors', '0'); // prod: hatalar loga yazilir, tarayiciya asla basilmaz
ini_set('log_errors', '1');

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Response.php';
require __DIR__ . '/lib/Ids.php';
require __DIR__ . '/lib/Auth.php';
require __DIR__ . '/lib/AuditLogger.php';
require __DIR__ . '/lib/Scope.php';
require __DIR__ . '/lib/Crypto.php';
require __DIR__ . '/lib/GoogleOAuth.php';
require __DIR__ . '/lib/GoogleDriveClient.php';
require __DIR__ . '/lib/ZipStreamer.php';

set_exception_handler(function (Throwable $e): void {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    Response::error('Sunucu hatası oluştu.', 500);
});

Auth::startSession();
// See Auth::releaseSessionLock() — every route except login/logout only reads
// $_SESSION, so there's no reason to hold PHP's exclusive session file lock for
// this request's entire lifetime (which, for an upload/download, can be minutes).
Auth::releaseSessionLock();
