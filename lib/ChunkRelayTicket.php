<?php

/**
 * Signs a compact, self-contained ticket handed to the browser and then to the
 * Cloudflare Worker (cloudflare-worker/) so the Worker can relay one upload's
 * chunks straight into a Google Drive resumable session without ever touching
 * our database or holding a Google credential of its own — the session URI
 * itself is already a self-authenticating Drive capability token (neither
 * GoogleDriveClient::uploadChunk nor queryResumableProgress ever sends an
 * Authorization header to it, only createResumableSession does). This ticket
 * only proves "our PHP backend, after checking real folder access, authorized
 * relaying to exactly this session" — verification happens in the Worker (JS),
 * using the same shared secret; PHP only ever mints, never verifies, one.
 */
final class ChunkRelayTicket
{
    public static function mint(string $uploadId, string $sessionUri, int $totalBytes, int $ttlSeconds = 7 * 86400): string
    {
        $payload = [
            'v' => 1,
            'uploadId' => $uploadId,
            'sessionUri' => $sessionUri,
            'totalBytes' => $totalBytes,
            'exp' => time() + $ttlSeconds,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payloadPart = self::base64url($payloadJson);
        $signature = hash_hmac('sha256', $payloadJson, self::key(), true);
        $signaturePart = self::base64url($signature);
        return $payloadPart . '.' . $signaturePart;
    }

    // Config stores this as a hex string (same "bin2hex(random_bytes(32))"
    // convention as app_secret, see Crypto.php) — the Worker side hex-decodes
    // the same way before importing it as its HMAC key, so both sides must
    // agree on raw bytes, not the hex text itself.
    private static function key(): string
    {
        $hex = (string) Config::get('chunk_relay_secret');
        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('chunk_relay_secret 32 baytlik (64 hex karakter) bir anahtar olmali.');
        }
        return $key;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
