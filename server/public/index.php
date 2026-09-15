<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $roots = [
        __DIR__ . '/../app/Core/',
        __DIR__ . '/../app/Controllers/',
        __DIR__ . '/../app/Services/',
    ];

    foreach ($roots as $root) {
        $file = $root . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

$app = require __DIR__ . '/../config/app.php';
date_default_timezone_set($app['timezone']);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string)max(
    (int)($app['session_idle_timeout_seconds'] ?? 1800),
    (int)($app['session_absolute_timeout_seconds'] ?? 28800)
));
session_name($app['session_name']);
$sessionPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') ?: '/';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $sessionPath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

set_exception_handler(function (Throwable $exception): void {
    error_log((string)$exception);
    http_response_code(500);
    if (Request::expectsJson()) {
        Response::json(['error' => 'Server error'], 500);
    }
    echo '<h1>Server Error</h1><p>An unexpected error occurred.</p>';
});

$router = new Router();

$router->get('/', [DashboardController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/login/google', [AuthController::class, 'googleRedirect']);
$router->get('/login/google/callback', [AuthController::class, 'googleCallback']);
$router->get('/login/mfa', [MfaController::class, 'challenge']);
$router->post('/login/mfa', [MfaController::class, 'verify']);
$router->get('/login/mfa/setup', [MfaController::class, 'loginSetup']);
$router->post('/login/mfa/setup', [MfaController::class, 'enableForLogin']);
$router->get('/login/mfa/recovery', [MfaController::class, 'loginRecovery']);
$router->post('/login/mfa/recovery', [MfaController::class, 'finishLoginEnrollment']);
$router->post('/login/mfa/cancel', [MfaController::class, 'cancelChallenge']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/users/mfa/reset', [MfaController::class, 'reset']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/agent/download', [AgentDownloadController::class, 'download']);
$router->get('/settings', [SettingController::class, 'index']);
$router->post('/settings/agent-registration-key', [SettingController::class, 'updateAgentRegistrationKey']);
$router->get('/task', [AssignmentController::class, 'index']);
$router->post('/task/update', [AssignmentController::class, 'update']);
$router->post('/task/cancel', [AssignmentController::class, 'cancel']);
$router->post('/task/delete-completed', [AssignmentController::class, 'deleteCompleted']);
$router->get('/device', [AssetController::class, 'index']);
$router->get('/unassigned-devices', [AssetController::class, 'unassigned']);
$router->post('/unassigned-devices/assign', [AssetController::class, 'assignOffice']);
$router->post('/device/change-assignment', [AssetController::class, 'changeAssignment']);
$router->post('/device/unassign-office', [AssetController::class, 'unassignOffice']);
$router->post('/device/delete', [AssetController::class, 'delete']);
$router->post('/device/update-assignments/store', [AssetController::class, 'storeUpdateAssignment']);
$router->post('/device/maintenance/delete', [AssetController::class, 'deleteMaintenance']);
$router->post('/device/software-updates/delete', [AssetController::class, 'deleteSoftwareUpdate']);
$router->post('/device/driver-updates/delete', [AssetController::class, 'deleteDriverUpdate']);
$router->post('/device/software-sync', [AssetController::class, 'requestSoftwareSync']);
$router->get('/device/software-sync-status', [AssetController::class, 'softwareSyncStatus']);
$router->post('/device/driver-sync', [AssetController::class, 'requestDriverSync']);
$router->get('/device/driver-sync-status', [AssetController::class, 'driverSyncStatus']);
$router->get('/device/export', [AssetController::class, 'export']);

$router->get('/users', [UserController::class, 'index']);
$router->get('/users/create', [UserController::class, 'create']);
$router->post('/users/store', [UserController::class, 'store']);
$router->get('/users/edit', [UserController::class, 'edit']);
$router->post('/users/update', [UserController::class, 'update']);
$router->post('/users/disable', [UserController::class, 'disable']);
$router->post('/users/enable', [UserController::class, 'enable']);
$router->post('/users/delete', [UserController::class, 'delete']);
$router->post('/users/permissions', [UserController::class, 'updatePermissions']);
$router->post('/delete-requests/store', [DeleteRequestController::class, 'store']);
$router->post('/delete-requests/approve', [DeleteRequestController::class, 'approve']);
$router->post('/delete-requests/reject', [DeleteRequestController::class, 'reject']);

$router->get('/employees', [EmployeeController::class, 'index']);
$router->post('/employees/save', [EmployeeController::class, 'save']);
$router->post('/employees/delete', [EmployeeController::class, 'delete']);

$router->get('/offices', [OfficeController::class, 'index']);
$router->post('/offices/store', [OfficeController::class, 'save']);
$router->post('/offices/delete', [OfficeController::class, 'delete']);

$router->get('/reports', [ReportController::class, 'index']);
$router->post('/settings/network-collectors', [NetworkDeviceController::class, 'configure']);
$router->post('/device/network-devices', [NetworkDeviceController::class, 'addDevice']);
$router->post('/device/network-device-sync', [NetworkDeviceController::class, 'syncDevice']);
$router->get('/device/network-device-sync-status', [NetworkDeviceController::class, 'syncStatus']);
$router->get('/api/agent/network-printer-targets', [NetworkDeviceController::class, 'printerTargets']);
$router->post('/api/agent/network-devices', [NetworkDeviceController::class, 'upload']);
$router->get('/reports/export', [ReportController::class, 'export']);

$router->post('/api/agent/register', [AgentApiController::class, 'register']);
$router->post('/api/agent/inventory', [AgentApiController::class, 'inventory']);
$router->post('/api/agent/heartbeat', [AgentApiController::class, 'heartbeat']);
$router->post('/api/agent/offline', [AgentApiController::class, 'offline']);
$router->post('/api/agent/logs', [AgentApiController::class, 'logs']);

$router->dispatch(Request::method(), Request::path());
