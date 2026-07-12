<?php

/** Loads config.php (outside git, ideally outside webroot) once and exposes values. */
final class Config
{
    private static ?array $data = null;

    private static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        // Look one directory above api/ first (outside webroot if the account
        // layout allows it), then fall back to api/config.php.
        $candidates = [
            __DIR__ . '/../../config.php',
            __DIR__ . '/../config.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                self::$data = require $path;
                return self::$data;
            }
        }

        http_response_code(500);
        die('config.php bulunamadi. api/config.example.php dosyasini kopyalayip gercek degerlerle doldur.');
    }

    public static function get(string $key)
    {
        $data = self::load();
        return $data[$key] ?? null;
    }
}
