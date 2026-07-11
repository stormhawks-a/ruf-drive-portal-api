<?php

function auth_login(array $params): void
{
    $body = Response::body();
    $identifier = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($identifier === '' || $password === '') {
        Response::error('E-posta ve şifre zorunlu.', 422);
    }

    $user = Db::queryOne(
        'SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1',
        [$identifier, $identifier]
    );

    if ($user === null || !Auth::verify($password, $user['password_hash'])) {
        Response::error('E-posta adresi veya şifre hatalı.', 401);
    }

    Auth::login($user);
    AuditLogger::log($user['id'], $user['name'], $user['role'], 'LOGIN', 'Giriş yapıldı.');

    unset($user['password_hash']);
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
