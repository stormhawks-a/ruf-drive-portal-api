<?php

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    // Same-origin deployment, but harmless to answer preflights cleanly if ever probed.
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Strip the directory this script lives in (works whether the API is served
// from teslim.workonruf.com/api/ or from an api.* subdomain root).
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$path = $uri;
if ($scriptDir !== '' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
$path = '/' . ltrim($path, '/');
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

$routes = [];
foreach (glob(__DIR__ . '/routes/*.php') as $routeFile) {
    $defs = require $routeFile;
    if (is_array($defs)) {
        $routes = array_merge($routes, $defs);
    }
}

foreach ($routes as $route) {
    [$routeMethod, $pattern, $handler] = $route;
    if ($routeMethod !== $method) {
        continue;
    }
    if (preg_match($pattern, $path, $matches)) {
        $namedParams = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler($namedParams);
        exit;
    }
}

Response::error('Bulunamadı.', 404);
