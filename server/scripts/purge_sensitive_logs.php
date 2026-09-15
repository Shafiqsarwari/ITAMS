<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/Database.php';

$db = Database::connection();
$retention = [
    'agent_logs' => max(1, (int)(getenv('AGENT_LOG_RETENTION_DAYS') ?: 30)),
    'login_logs' => max(1, (int)(getenv('LOGIN_LOG_RETENTION_DAYS') ?: 90)),
    'audit_logs' => max(1, (int)(getenv('AUDIT_LOG_RETENTION_DAYS') ?: 365)),
];

foreach ($retention as $table => $days) {
    $deleted = $db->exec(
        "DELETE FROM {$table} WHERE created_at < NOW() - INTERVAL '{$days} days'"
    );
    echo $table . ': ' . (int)$deleted . " row(s) deleted\n";
}
