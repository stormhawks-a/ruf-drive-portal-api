<?php

/** One-time OAuth2 authorization + ongoing token refresh for the single Drive account this app uses. */
final class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public static function buildAuthUrl(): string
    {
        $google = Config::get('google');
        $params = [
            'client_id' => $google['client_id'],
            'redirect_uri' => $google['redirect_uri'],
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent', // forces a refresh_token even on repeat authorizations
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /** Exchanges a one-time authorization code for tokens and persists them. */
    public static function handleCallback(string $code): void
    {
        $google = Config::get('google');
        $response = self::post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $google['client_id'],
            'client_secret' => $google['client_secret'],
            'redirect_uri' => $google['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);

        if (!isset($response['refresh_token'])) {
            throw new RuntimeException(
                'Google refresh_token dondurmedi. Muhtemelen bu hesap zaten yetkilendirilmisti - ' .
                'Google hesabinin "Uygulama izinleri" sayfasindan bu uygulamayi kaldirip tekrar dene.'
            );
        }

        self::saveTokens($response['refresh_token'], $response['access_token'], (int) $response['expires_in']);
    }

    /** Returns a valid access token, refreshing it first if it's expired or missing. */
    public static function getAccessToken(): string
    {
        $row = Db::queryOne('SELECT * FROM google_oauth_tokens WHERE id = 1');
        if ($row === null || $row['refresh_token_encrypted'] === null) {
            throw new RuntimeException('Google Drive henuz yetkilendirilmedi (oauth_authorize.php calistirilmali).');
        }

        $expiresAt = $row['access_token_expires_at'] ? strtotime($row['access_token_expires_at']) : 0;
        if ($row['access_token'] && $expiresAt > time() + 60) {
            return $row['access_token'];
        }

        $refreshToken = Crypto::decrypt($row['refresh_token_encrypted']);
        $google = Config::get('google');
        $response = self::post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $google['client_id'],
            'client_secret' => $google['client_secret'],
            'grant_type' => 'refresh_token',
        ]);

        $newExpiresAt = date('Y-m-d H:i:s', time() + (int) $response['expires_in']);
        Db::execute(
            'UPDATE google_oauth_tokens SET access_token = ?, access_token_expires_at = ? WHERE id = 1',
            [$response['access_token'], $newExpiresAt]
        );

        return $response['access_token'];
    }

    private static function saveTokens(string $refreshToken, string $accessToken, int $expiresIn): void
    {
        $encrypted = Crypto::encrypt($refreshToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        Db::execute(
            'INSERT INTO google_oauth_tokens (id, refresh_token_encrypted, access_token, access_token_expires_at)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE refresh_token_encrypted = VALUES(refresh_token_encrypted),
                                     access_token = VALUES(access_token),
                                     access_token_expires_at = VALUES(access_token_expires_at)',
            [$encrypted, $accessToken, $expiresAt]
        );
    }

    private static function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($data)) {
            throw new RuntimeException('Google OAuth istegi basarisiz (HTTP ' . $status . '): ' . $body);
        }
        return $data;
    }
}
