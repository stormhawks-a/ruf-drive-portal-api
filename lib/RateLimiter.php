<?php

/**
 * DB-backed brute-force throttle for login and share-link-password attempts.
 * No external cache (Redis/Memcached) is available on this shared/VPS setup,
 * so a small table (login_throttle) doubles as the counter — one row per
 * failed attempt, counted within a sliding window per "bucket" key. Cheap
 * enough at this app's traffic scale; a real distributed-attack volume would
 * need a different approach, but that's not this app's threat model.
 */
final class RateLimiter
{
    /**
     * Throws a 429 if $bucketKey already has $maxAttempts or more failures
     * recorded within the last $windowSeconds. Call this BEFORE checking the
     * password, so a locked-out bucket never even reaches the (slow, bcrypt)
     * verify step.
     */
    public static function guard(string $bucketKey, int $maxAttempts, int $windowSeconds): void
    {
        $count = self::countRecent($bucketKey, $windowSeconds);
        if ($count >= $maxAttempts) {
            $minutes = (int) ceil($windowSeconds / 60);
            Response::error("Çok fazla başarısız deneme yapıldı. Lütfen {$minutes} dakika sonra tekrar deneyin.", 429);
        }
    }

    /** Records one failed attempt against $bucketKey. Call only on a WRONG credential/password. */
    public static function recordFailure(string $bucketKey): void
    {
        Db::execute('INSERT INTO login_throttle (bucket_key) VALUES (?)', [$bucketKey]);
    }

    /** Clears a bucket's history — call on a SUCCESSFUL auth so a legitimate
        user isn't left one mistyped-password away from a lockout right after
        proving who they are. */
    public static function clear(string $bucketKey): void
    {
        Db::execute('DELETE FROM login_throttle WHERE bucket_key = ?', [$bucketKey]);
    }

    /** Counts attempts within the window and, as a side effect, deletes this
        bucket's rows that fell out of it — keeps the table from growing
        unbounded without needing a separate cleanup cron. */
    private static function countRecent(string $bucketKey, int $windowSeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
        Db::execute('DELETE FROM login_throttle WHERE bucket_key = ? AND created_at < ?', [$bucketKey, $cutoff]);
        $row = Db::queryOne('SELECT COUNT(*) AS c FROM login_throttle WHERE bucket_key = ?', [$bucketKey]);
        return (int) ($row['c'] ?? 0);
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
