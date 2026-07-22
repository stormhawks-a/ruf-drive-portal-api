<?php

function auth_login(array $params): void
{
    $body = Response::body();
    $identifier = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($identifier === '' || $password === '') {
        Response::error('E-posta ve şifre zorunlu.', 422);
    }

    // Two independent buckets: per-identifier (stops repeated guesses against
    // one account, whoever's IP they come from) and per-IP (stops one source
    // spraying many different accounts). Checked before the bcrypt verify
    // below so a locked-out bucket doesn't even pay that cost.
    $ipBucket = 'login_ip:' . RateLimiter::clientIp();
    $idBucket = 'login_id:' . mb_strtolower($identifier);
    RateLimiter::guard($ipBucket, 20, 900);
    RateLimiter::guard($idBucket, 5, 900);

    $user = Db::queryOne(
        'SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1',
        [$identifier, $identifier]
    );

    if ($user === null || !Auth::verify($password, $user['password_hash'])) {
        RateLimiter::recordFailure($ipBucket);
        RateLimiter::recordFailure($idBucket);
        Response::error('E-posta adresi veya şifre hatalı.', 401);
    }
    RateLimiter::clear($idBucket);

    Auth::login($user);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'LOGIN', 'Giriş yapıldı.');

    // SELECT * pulls password_hash AND the newer password_encrypted column — both
    // are sensitive and neither belongs in a response body, even the user's own.
    unset($user['password_hash'], $user['password_encrypted']);
    Response::json(['user' => $user]);
}

function auth_logout(array $params): void
{
    Auth::logout();
    Response::json(['ok' => true]);
}

function auth_me(array $params): void
{
    $user = Auth::currentUser();
    if ($user === null) {
        Response::json(['user' => null]);
    }
    Response::json(['user' => $user]);
}

return [
    ['POST', '#^/login$#', 'auth_login'],
    ['POST', '#^/logout$#', 'auth_logout'],
    ['GET', '#^/me$#', 'auth_me'],
];
