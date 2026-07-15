<?php

final class Auth
{
    private static bool $started = false;

    // Real accounts (ADMIN/EDITOR/CUSTOMER) use a native PHP session under this
    // cookie. Anonymous share-link visits used to share that SAME session/cookie,
    // and opening a customer's share link in a new tab called session_regenerate_id()
    // on it (cookies are shared browser-wide, not per-tab) — wiping the admin's own
    // login in every other tab of that browser. Share-link identity is now a
    // separate, self-contained encrypted cookie instead of a second PHP session:
    // PHP only reliably supports one active session per request (a second
    // session_name()/session_start() call silently no-ops and reuses the first
    // session's id, which was the actual bug here), so it can't just be "another
    // session store" — it has to not go through the session mechanism at all.
    private const AUTH_COOKIE = 'ruf_session';
    private const SHARE_COOKIE = 'ruf_share_session';

    // Neither staff/customer logins nor share links should time out during normal,
    // possibly hours-long use (e.g. a large file transfer) — only real logout ends
    // them. 24h is generous slack on top of that, not a "stay logged in forever" cookie.
    private const SESSION_LIFETIME_SECONDS = 60 * 60 * 24;

    private static ?string $shareLinkId = null;

    public static function startSession(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        $secure = (bool) Config::get('secure_cookies');
        ini_set('session.gc_maxlifetime', (string) self::SESSION_LIFETIME_SECONDS);
        session_set_cookie_params([
            'lifetime' => 0, // still a browser-session cookie — closing the browser ends it
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name(self::AUTH_COOKIE);
        session_start();

        if (isset($_COOKIE[self::SHARE_COOKIE])) {
            try {
                self::$shareLinkId = Crypto::decrypt($_COOKIE[self::SHARE_COOKIE]);
            } catch (Throwable $e) {
                self::$shareLinkId = null;
            }
        }
    }

    /**
     * Releases the exclusive session file lock PHP holds for the whole request by
     * default. Every route but login/logout only ever READS $_SESSION, so there's
     * no reason to keep holding that lock for a request's entire lifetime — and a
     * slow one (a many-GB file download/upload streaming for minutes) used to
     * block every OTHER concurrent request from the same logged-in browser
     * (several chunk-upload requests in flight at once, or just a second tab)
     * behind it, silently serializing them regardless of how parallel the client
     * side tried to be.
     */
    public static function releaseSessionLock(): void
    {
        session_write_close();
    }

    public static function login(array $userRow): void
    {
        // The bootstrap already released the lock for this request (see
        // releaseSessionLock) — reacquire it just long enough to write.
        session_start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userRow['id'];
        $_SESSION['role'] = $userRow['role'];
        session_write_close();
    }

    public static function loginShareLink(string $sharedLinkId): void
    {
        self::$shareLinkId = $sharedLinkId;
        setcookie(self::SHARE_COOKIE, Crypto::encrypt($sharedLinkId), [
            'expires' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => (bool) Config::get('secure_cookies'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function logout(): void
    {
        session_start();
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
        return self::$shareLinkId;
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
