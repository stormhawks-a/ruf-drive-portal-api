<?php

function audit_list(array $params): void
{
    Auth::requireRole('ADMIN');
    $rows = Db::query('SELECT * FROM audit_logs ORDER BY ts DESC LIMIT 200');
    Response::json(['logs' => $rows]);
}

/** Permanently wipes the audit trail — the frontend gates this behind its own
    confirmation prompt, but nothing stops it being called again, so this stays
    a hard, unconditional delete rather than a soft/undoable one. */
function audit_clear(array $params): void
{
    $actor = Auth::requireRole('ADMIN');
    Db::execute('DELETE FROM audit_logs');
    // The one entry that survives the wipe — so there's still a record of who
    // cleared the trail and when, even though everything before it is gone.
    AuditLogger::log($actor['id'], $actor['name'], $actor['role'], 'PERMISSION_CHANGE', 'İşlem logları temizlendi.');
    Response::json(['ok' => true]);
}

return [
    ['GET', '#^/logs$#', 'audit_list'],
    ['DELETE', '#^/logs$#', 'audit_clear'],
];
