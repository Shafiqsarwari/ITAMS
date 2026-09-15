<?php
$currentUser = Auth::user();
$renderAppShell = $currentUser && empty($standaloneAuth);
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$modalFlash = [];
$appConfig = require __DIR__ . '/../../config/app.php';
$collectorOfflineAfter = max(1, (int)$appConfig['offline_after_minutes']);
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$url = fn(string $path) => ($basePath ?: '') . '/' . ltrim($path, '/');
$currentPath = Request::path();
$mfaModalState = (string)Request::input('mfa', '');
$modalFlash = $currentUser && !($currentPath === '/users' && $mfaModalState !== '') ? $flash : [];
$navActive = static fn(array $paths): string => in_array($currentPath, $paths, true) ? ' is-active' : '';
$assignmentAlert = $currentUser ? AssignmentAlertService::summaryForUser((int)$currentUser['id']) : ['pending_count' => 0, 'has_unseen' => false];
$canManageAgentRegistration = $currentUser
    && ScopeService::isAdmin($currentUser)
    && empty($currentUser['office_id']);
$sidebarCollectorOffices = [];
$sidebarCollectors = [];
$hasAgentRegistrationKeyOverride = false;
if ($currentUser) {
    try {
        $sidebarDb = Database::connection();
        if (ScopeService::isAdmin($currentUser)) {
            $sidebarCollectorOffices = $sidebarDb->query('SELECT id, name FROM offices ORDER BY name')->fetchAll();
        } elseif (!empty($currentUser['office_id'])) {
            $accessibleOfficeIds = ScopeService::descendantOfficeIds((int)$currentUser['office_id']);
            if ($accessibleOfficeIds) {
                $officePlaceholders = implode(',', array_fill(0, count($accessibleOfficeIds), '?'));
                $officeStatement = $sidebarDb->prepare("SELECT id, name FROM offices WHERE id IN ({$officePlaceholders}) ORDER BY name");
                $officeStatement->execute($accessibleOfficeIds);
                $sidebarCollectorOffices = $officeStatement->fetchAll();
            }
        }

        $visibleOfficeIds = array_map('intval', array_column($sidebarCollectorOffices, 'id'));
        if ($visibleOfficeIds) {
            $collectorPlaceholders = implode(',', array_fill(0, count($visibleOfficeIds), '?'));
            $collectorStatement = $sidebarDb->prepare("SELECT computers.id, computers.computer_name, computers.office_id, COALESCE(network_collectors.enabled, 0) AS enabled
                FROM network_collectors
                JOIN computers ON computers.id = network_collectors.computer_id
                WHERE computers.office_id IN ({$collectorPlaceholders})
                  AND computers.agent_token_hash IS NOT NULL
                  AND computers.agent_uninstalled_at IS NULL
                  AND computers.last_checkin_at >= NOW() - INTERVAL {$collectorOfflineAfter} MINUTE
                ORDER BY computers.computer_name");
            $collectorStatement->execute($visibleOfficeIds);
            $sidebarCollectors = $collectorStatement->fetchAll();
        }

        if ($canManageAgentRegistration) {
            $hasAgentRegistrationKeyOverride = AppSettingService::get('agent_registration_key') !== null;
        }
    } catch (Throwable $sidebarSettingsError) {
        error_log('Could not load sidebar agent tools: ' . $sidebarSettingsError->getMessage());
    }
}
$assetVersion = static function (string $path): string {
    $fullPath = __DIR__ . '/../../public/' . ltrim($path, '/');
    return is_file($fullPath) ? (string)filemtime($fullPath) : '1';
};
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function af_date(mixed $value): string { return AppDate::date($value); }
function af_datetime(mixed $value): string { return AppDate::dateTime($value); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? $title . ' | ' . $appConfig['name'] : $appConfig['name']) ?></title>
    <link rel="icon" type="image/png" href="<?= $url('/static/img/system-logo.png') ?>?v=<?= e($assetVersion('static/img/system-logo.png')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.css" rel="stylesheet">
    <link href="<?= $url('/static/css/app.css') ?>?v=<?= e($assetVersion('static/css/app.css')) ?>" rel="stylesheet">
</head>
<body>
<?php if ($renderAppShell): ?>
<div class="app-shell">
    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
    <aside class="sidebar" id="appSidebar" aria-label="Main navigation">
        <div class="brand">
            <a class="sidebar-brand-link" href="<?= $url('/dashboard') ?>" aria-label="Open dashboard">
                <img class="brand-logo-image" src="<?= $url('/static/img/system-logo.png') ?>?v=<?= e($assetVersion('static/img/system-logo.png')) ?>" alt="IT Asset Monitoring System logo">
            </a>
            <button class="btn btn-sm btn-outline-light sidebar-close" id="sidebarClose" type="button" aria-label="Close navigation"><i class="bi bi-x-lg"></i></button>
        </div>
        <nav>
            <span class="sidebar-nav-category">Overview</span>
            <a class="<?= e($navActive(['/', '/dashboard'])) ?>" href="<?= $url('/dashboard') ?>"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>

            <span class="sidebar-nav-category">Asset Management</span>
            <a class="<?= e($navActive(['/device'])) ?>" href="<?= $url('/device') ?>"><i class="bi bi-pc-display"></i> Device Management</a>
            <?php if (Auth::can('offices.manage')): ?><a class="<?= e($navActive(['/unassigned-devices'])) ?>" href="<?= $url('/unassigned-devices') ?>"><i class="bi bi-buildings"></i> Unassigned Device</a><?php endif; ?>
            <a class="sidebar-assignment-link<?= e($navActive(['/task'])) ?><?= $assignmentAlert['pending_count'] > 0 ? ' has-pending' : '' ?>" href="<?= $url('/task') ?>"><i class="bi bi-person-check"></i><span>Task</span><?php if ($assignmentAlert['pending_count'] > 0): ?><span class="sidebar-assignment-count" aria-label="<?= number_format($assignmentAlert['pending_count']) ?> unfinished tasks"><?= number_format($assignmentAlert['pending_count']) ?></span><?php endif; ?></a>

            <span class="sidebar-nav-category">Organization</span>
            <?php if (Auth::can('offices.manage')): ?><a class="sidebar-office-link<?= e($navActive(['/offices'])) ?>" href="<?= $url('/offices') ?>"><i class="bi bi-diagram-3"></i><span>Office</span></a><?php endif; ?>
            <?php if (Auth::can('employees.manage')): ?><a class="<?= e($navActive(['/employees'])) ?>" href="<?= $url('/employees') ?>"><i class="bi bi-person-vcard"></i> Employees</a><?php endif; ?>
            <a class="<?= e($navActive(['/users', '/users/create', '/users/edit'])) ?>" href="<?= $url('/users') ?>"><i class="bi bi-grid"></i> Users</a>
            <?php if (Auth::can('reports.view')): ?><a class="<?= e($navActive(['/reports'])) ?>" href="<?= $url('/reports') ?>"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a><?php endif; ?>

            <span class="sidebar-nav-category">Agent Tools</span>
            <a href="#" role="button" data-bs-toggle="modal" data-bs-target="#downloadAgentModal"><i class="bi bi-download"></i> Download Agent</a>
            <a href="#" role="button" data-bs-toggle="modal" data-bs-target="#collectorDeviceModal"><i class="bi bi-router"></i> Collector Device</a>
            <?php if ($canManageAgentRegistration): ?><a href="#" role="button" data-bs-toggle="modal" data-bs-target="#agentRegistrationModal"><i class="bi bi-shield-lock"></i> Agent Registration</a><?php endif; ?>
        </nav>
        <div class="sidebar-account">
            <div class="sidebar-account-profile">
                <span class="sidebar-account-avatar"><?= e(strtoupper(substr((string)($currentUser['name'] ?? 'U'), 0, 1))) ?></span>
                <div>
                    <strong><?= e($currentUser['name'] ?? 'User') ?></strong>
                    <small><?= e(ScopeService::isAdmin($currentUser) && empty($currentUser['office_id'])
                        ? 'Sysadmin'
                        : ScopeService::officeLabel($currentUser['office_id'] ?? null)) ?></small>
                </div>
            </div>
            <form method="post" action="<?= $url('/logout') ?>">
                <?= Csrf::field() ?>
                <button class="btn sidebar-logout"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </form>
        </div>
    </aside>
    <main class="content<?= in_array($currentPath, ['/', '/dashboard'], true) ? ' content-dashboard' : '' ?>">
        <header class="topbar">
            <button class="btn btn-outline-secondary sidebar-toggle" id="sidebarToggle" type="button" aria-controls="appSidebar" aria-expanded="false" aria-label="Open navigation">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-title">
                <h1><?= e($title ?? 'Dashboard') ?></h1>
                <small><?= e($subtitle ?? (($currentUser['role_name'] ?? 'User') . ' · ' . ScopeService::officeLabel($currentUser['office_id'] ?? null))) ?></small>
            </div>
        </header>
        <?php require $viewFile; ?>
    </main>
</div>
<?php else: ?>
    <?php require $viewFile; ?>
<?php endif; ?>

<div class="modal fade app-message-modal" id="appMessageModal" tabindex="-1" aria-labelledby="appMessageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <button type="button" class="btn-close app-message-close" data-bs-dismiss="modal" aria-label="Close"></button>
            <div class="modal-body app-message-body">
                <span class="app-message-icon" id="appMessageModalIcon" aria-hidden="true"><i class="bi bi-info-lg"></i></span>
                <div class="app-message-copy">
                    <h2 class="modal-title" id="appMessageModalLabel">Message</h2>
                    <p id="appMessageModalBody"></p>
                </div>
            </div>
            <div class="modal-footer app-message-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="appFlashMessages"><?= json_encode($modalFlash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php if ($renderAppShell): ?>
<div class="modal fade sidebar-tool-modal" id="downloadAgentModal" tabindex="-1" aria-labelledby="downloadAgentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="downloadAgentModalLabel"><i class="bi bi-download"></i> Download Agent</h2>
                    <p class="text-secondary small mb-0">Install the Windows monitoring agent on a computer you want to add to ITAMS.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="agent-download-actions">
                    <a class="btn btn-primary" href="<?= $url('/agent/download') ?>"><i class="bi bi-download"></i> Download Agent</a>
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#agentInstallGuide" aria-expanded="false" aria-controls="agentInstallGuide"><i class="bi bi-question-circle"></i> How to Install</button>
                </div>
                <div class="collapse" id="agentInstallGuide">
                    <div class="agent-install-guide">
                        <h3>How to install the agent</h3>
                        <ol>
                            <li>Download the installer, right-click it, and select <strong>Run as administrator</strong>.</li>
                            <li>Enter the server connection details supplied by your administrator.</li>
                            <li>Enter the <strong>Registration Key</strong> supplied by your all-offices administrator.</li>
                            <li>If this computer will scan printers, switches, access points, or other network devices, select <strong>Make this device a collector computer</strong>. Leave it unchecked for normal computers.</li>
                            <li>On the <strong>Agent Timing</strong> screen, keep the recommended default values unless your administrator asks you to change them.</li>
                            <li>Complete the installation. The computer will register and upload its inventory automatically.</li>
                        </ol>
                        <div class="agent-timing-guide">
                            <h3>Agent timing settings</h3>
                            <dl>
                                <div>
                                    <dt>Inventory Interval Minutes — default 60</dt>
                                    <dd>How often the agent sends a complete hardware, software, and device inventory. A value of 60 means once every hour.</dd>
                                </div>
                                <div>
                                    <dt>Heartbeat Seconds — default 10</dt>
                                    <dd>How often the agent tells ITAMS that the computer is still connected. This keeps its online or not-connected status accurate and does not send the full inventory.</dd>
                                </div>
                                <div>
                                    <dt>Change Scan Seconds — default 60</dt>
                                    <dd>How often the agent performs a quick check for changes. A value of 60 means once per minute; information is uploaded when a relevant change is found.</dd>
                                </div>
                            </dl>
                            <p><i class="bi bi-info-circle"></i> Lower values create more network and server activity. The defaults are recommended for normal installations.</p>
                        </div>
                        <div class="agent-collector-note"><i class="bi bi-router"></i><span>After installing a collector computer, open <strong>Collector Device</strong> from the sidebar and enable its authorization for the correct office.</span></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade sidebar-tool-modal" id="collectorDeviceModal" tabindex="-1" aria-labelledby="collectorDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="collectorDeviceModalLabel"><i class="bi bi-router"></i> Collector Device</h2>
                    <p class="text-secondary small mb-0">Authorize a collector computer for an office you can access.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (!$sidebarCollectors): ?>
                    <div class="alert alert-info mb-0">No collector computers are available. Install the agent and select “Make this device a collector computer” during setup.</div>
                <?php else: ?>
                    <form method="post" action="<?= $url('/settings/network-collectors') ?>" class="sidebar-collector-form" data-collector-settings-form>
                        <?= Csrf::field() ?>
                        <label>Office
                            <select name="office_id" class="form-select" required data-collector-office>
                                <option value="">Select office</option>
                                <?php foreach ($sidebarCollectorOffices as $office): ?><option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Collector computer
                            <select name="computer_id" class="form-select" required disabled data-collector-computer>
                                <option value="">Select collector computer</option>
                                <?php foreach ($sidebarCollectors as $collector): ?><option value="<?= (int)$collector['id'] ?>" data-office-id="<?= (int)$collector['office_id'] ?>"><?= e($collector['computer_name']) ?> (<?= $collector['enabled'] ? 'Enabled' : 'Disabled' ?>)</option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Authorization
                            <select name="enabled" class="form-select"><option value="0">Disabled</option><option value="1">Enabled</option></select>
                        </label>
                        <div class="sidebar-tool-actions">
                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageAgentRegistration): ?>
<div class="modal fade sidebar-tool-modal" id="agentRegistrationModal" tabindex="-1" aria-labelledby="agentRegistrationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="agentRegistrationModalLabel"><i class="bi bi-shield-lock"></i> Agent Registration</h2>
                    <p class="text-secondary small mb-0">Control the key used when a device agent registers with this server.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= $url('/settings/agent-registration-key') ?>" autocomplete="off">
                <div class="modal-body sidebar-registration-form">
                    <?= Csrf::field() ?>
                    <span class="soft-pill align-self-start"><?= $hasAgentRegistrationKeyOverride ? 'UI key active' : 'Config key active' ?></span>
                    <label>New Registration Key
                        <input class="form-control" type="password" name="agent_registration_key" minlength="32" required autocomplete="new-password">
                    </label>
                    <label>Confirm Registration Key
                        <input class="form-control" type="password" name="agent_registration_key_confirmation" minlength="32" required autocomplete="new-password">
                    </label>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-shield-lock"></i> Update Key</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirmDeleteModalLabel">Confirm Delete</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmDeleteMessage">Are you sure?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteButton"><i class="bi bi-trash"></i> Delete</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="blockedUserActionModal" tabindex="-1" aria-labelledby="blockedUserActionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="blockedUserActionModalLabel">Action Not Allowed</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="blockedUserActionMessage"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<div class="sync-overlay d-none" id="softwareSyncOverlay" role="dialog" aria-modal="true" aria-labelledby="softwareSyncTitle">
    <div class="sync-dialog" id="softwareSyncDialog">
        <div class="sync-spinner" aria-hidden="true">
            <span class="spinner-border"></span>
            <i class="bi bi-check2-circle"></i>
            <i class="bi bi-exclamation-triangle"></i>
        </div>
        <div>
            <h2 class="sync-title" id="softwareSyncTitle">Syncing Software</h2>
            <p class="sync-message" id="softwareSyncMessage">Waiting for the endpoint agent to upload the latest software list.</p>
        </div>
        <button class="btn btn-outline-secondary" type="button" id="softwareSyncCancel">Cancel</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>
<?php if ($currentUser || Auth::pendingMfaPurpose() === 'enroll'): ?><script src="<?= $url('/static/js/qrcode.js') ?>?v=<?= e($assetVersion('static/js/qrcode.js')) ?>"></script><?php endif; ?>
<script src="<?= $url('/static/js/app.js') ?>?v=<?= e($assetVersion('static/js/app.js')) ?>"></script>
</body>
</html>

