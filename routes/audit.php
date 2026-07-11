<?php

function audit_list(array $params): void
{
    Auth::requireRole('ADMIN');
    $rows = Db::query('SELECT * FROM audit_logs ORDER BY ts DESC LIMIT 200');
    Response::json(['logs' => $rows]);
}

return [
    ['GET', '#^/logs$#', 'audit_list'],
];
