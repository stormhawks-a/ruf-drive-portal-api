<?php

/** AES-256-GCM encrypt/decrypt using app_secret as the key (for the Drive refresh token at rest). */
final class Crypto
{
    private static function key(): string
    {
        $hex = Config::get('app_secret');
        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('app_secret 32 baytlik (64 hex karakter) bir anahtar olmali.');
        }
        return $key;
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Sifreleme basarisiz.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Gecersiz sifreli veri.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Sifre cozme basarisiz (yanlis anahtar veya bozuk veri).');
        }
        return $plaintext;
    }
}
