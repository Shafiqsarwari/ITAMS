<?php

$local = is_file(__DIR__ . '/local.php') ? require __DIR__ . '/local.php' : [];
$localValue = static fn(string $key): string => trim((string)($local[$key] ?? ''));
$baseUrl = $localValue('base_url') !== '' ? $localValue('base_url') : (getenv('APP_URL') ?: 'http://localhost/asset-inventory/server/public');
$agentRegistrationKey = trim((string)(getenv('AGENT_REGISTRATION_KEY') ?: ''));
$googleClientId = $localValue('google_client_id') !== '' ? $localValue('google_client_id') : (getenv('GOOGLE_CLIENT_ID') ?: '');
$googleClientSecret = $localValue('google_client_secret') !== '' ? $localValue('google_client_secret') : (getenv('GOOGLE_CLIENT_SECRET') ?: '');

return [
    'name' => 'IT Asset Monitoring System',
    'brand_mark' => 'IT',
    'base_url' => $baseUrl,
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Kabul',
    'session_name' => 'asset_inventory_session',
    'agent_registration_key' => $agentRegistrationKey,
    'google_client_id' => $googleClientId,
    'google_client_secret' => $googleClientSecret,
    'offline_after_minutes' => 2,
    'agent_heartbeat_seconds' => 10,
    'agent_change_scan_seconds' => 60,
    'agent_inventory_interval_minutes' => 60,
    'session_idle_timeout_seconds' => 1800,
    'session_absolute_timeout_seconds' => 28800,
    'agent_max_json_bytes' => 1048576,
    'agent_max_logs_per_request' => 200,
    'agent_max_networks_per_inventory' => 64,
    'agent_max_software_per_inventory' => 5000,
    'agent_max_drivers_per_inventory' => 10000,
    'agent_max_log_context_bytes' => 8192,
    'agent_registration_rate_limit_per_5_minutes' => 20,
    'agent_inventory_rate_limit_per_minute' => 12,
    'agent_heartbeat_rate_limit_per_minute' => 120,
    'agent_log_rate_limit_per_minute' => 30,
];
