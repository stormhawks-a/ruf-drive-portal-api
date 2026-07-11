<?php

final class Auth
{
    private static bool $started = false;

    public static function startSession(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        $secure = (bool) Config::get('secure_cookies');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name((string) (Config::get('session_name') ?: 'ruf_session'));
        session_start();
    }

    public static function login(array $userRow): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userRow['id'];
        $_SESSION['role'] = $userRow['role'];
        unset($_SESSION['share_link_id']);
    }

    public static function loginShareLink(string $sharedLinkId): void
    {
        session_regenerate_id(true);
        $_SESSION['share_link_id'] = $sharedLinkId;
        unset($_SESSION['user_id'], $_SESSION['role']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /** Fresh row from DB every call — a deactivated/edited user takes effect immediately. */
    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $row = Db::queryOne(
            'SELECT id, name, email, username, role, avatar_url, folder_id, is_active FROM users WHERE id = ?',
            [$_SESSION['user_id']]
        );
        if ($row === null || (int) $row['is_active'] !== 1) {
            return null;
        }
        return $row;
    }

    public static function currentShareLinkId(): ?string
    {
        return $_SESSION['share_link_id'] ?? null;
    }

    public static function requireAuth(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            Response::error('Oturum açmanız gerekiyor.', 401);
        }
        return $user;
    }

    /** @param string|string[] $roles */
    public static function requireRole($roles): array
    {
        $user = self::requireAuth();
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role'], $allowed, true)) {
            Response::error('Bu işlem için yetkiniz yok.', 403);
        }
        return $user;
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
