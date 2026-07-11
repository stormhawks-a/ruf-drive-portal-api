<?php

final class AuditLogger
{
    public static function log(?string $userId, string $userName, string $userRole, string $action, string $details): void
    {
        Db::execute(
            'INSERT INTO audit_logs (user_id, user_name, user_role, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $userName, $userRole, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]
        );
    }
}
