<?php

/** Thin PDO wrapper. Always use prepared statements — never concatenate SQL. */
final class Db
{
    private static ?PDO $instance = null;

    public static function conn(): PDO
    {
        if (self::$instance === null) {
            $cfg = Config::get('db');
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                $cfg['name']
            );
            self::$instance = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }

    /** @return array<int, array<string, mixed>> */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::conn()->lastInsertId();
    }
}
