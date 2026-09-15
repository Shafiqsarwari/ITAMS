<?php
$currentHour = (int)date('G');
$greeting = $currentHour < 12 ? 'Good morning' : ($currentHour < 18 ? 'Good afternoon' : 'Good evening');
$cards = [
    ['Total Devices', $stats['total_computers'], $weeklyStats['total_computers'], 'bi-pc-display', 'All registered devices', 'total', $url('/device'), 'M3 31 C18 24 25 37 40 29 S61 35 76 22 S98 26 116 18'],
    ['Computers', $stats['computer_devices'], $weeklyStats['computer_devices'], 'bi-pc-display-horizontal', 'Agent-managed computers', 'online', $url('/device'), 'M3 33 C19 34 28 25 40 32 S62 42 75 27 S97 18 116 24'],
    ['Network Devices', $stats['network_devices'], $weeklyStats['network_devices'], 'bi-router', 'Printers and network equipment', 'offline', $url('/settings'), 'M3 27 C16 18 25 38 40 33 S59 17 74 24 S97 20 116 27'],
    ['Not Connected', $stats['not_connected_computers'], $weeklyStats['not_connected_computers'], 'bi-wifi-off', 'Connection issues', 'danger', $url('/device'), 'M3 35 C18 28 30 21 43 31 S63 43 77 35 S97 28 116 34'],
    ['Unassigned Devices', $stats['unassigned_devices'], $weeklyStats['unassigned_devices'], 'bi-inboxes', 'Waiting for office assignment', 'unassigned', $url('/unassigned-devices'), 'M3 34 C18 28 29 37 43 30 S65 25 79 35 S101 29 116 32'],
];
?>

<div class="dashboard-redesign">
    <section class="dashboard-hero-row">
        <div class="dashboard-greeting">
            <h2><?= e($greeting) ?>, <?= e(strtok((string)($currentUser['name'] ?? 'User'), ' ') ?: 'User') ?>!</h2>
            <p><?= e($subtitle ?? 'Real-time overview of your IT assets') ?></p>
        </div>
        <?php if (!empty($canFilterDashboard)): ?>
            <form class="dashboard-filter-strip" method="get" action="<?= $url('/dashboard') ?>" data-dashboard-filter-form>
                <input type="hidden" name="period" value="<?= e($activityPeriod) ?>">
                <label>
                    <i class="bi bi-buildings"></i>
                    <select name="office_id" data-dashboard-office-filter>
                        <option value="">All Offices</option>
                        <?php foreach ($dashboardOffices as $office): ?>
                            <option value="<?= (int)$office['id'] ?>" <?= (int)$selectedDashboardOfficeId === (int)$office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <i class="bi bi-person"></i>
                    <select name="user_id" data-dashboard-user-filter>
                        <option value="">All users</option>
                        <?php foreach ($dashboardUsers as $dashboardUser):
                            $dashboardUserAllOffices = (($dashboardUser['role_slug'] ?? '') === 'admin' && empty($dashboardUser['office_id']));
                        ?>
                            <option value="<?= (int)$dashboardUser['id'] ?>" data-office-id="<?= (int)($dashboardUser['office_id'] ?? 0) ?>" <?= $dashboardUserAllOffices ? 'data-all-offices="1"' : '' ?> <?= (int)$selectedDashboardUserId === (int)$dashboardUser['id'] ? 'selected' : '' ?>><?= e($dashboardUser['name'] ?? '-') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit"><i class="bi bi-funnel"></i> Filters</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="dashboard-card-grid">
        <?php foreach ($cards as $card): ?>
            <a class="metric-card is-<?= e($card[5]) ?>" href="<?= e($card[6]) ?>">
                <span class="metric-icon"><i class="bi <?= e($card[3]) ?>"></i></span>
                <span class="metric-label"><?= e($card[0]) ?></span>
                <strong><?= number_format((int)$card[1]) ?></strong>
                <small><?= e($card[4]) ?></small>
                <em><i class="bi bi-arrow-up-short"></i> +<?= number_format((int)$card[2]) ?> this week</em>
                <svg viewBox="0 0 120 48" preserveAspectRatio="none" aria-hidden="true"><path d="<?= e($card[7]) ?>"></path></svg>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="office-device-chart-panel" aria-labelledby="officeDeviceChartTitle">
        <div class="office-device-chart-header">
            <div class="module-heading">
                <span class="module-icon"><i class="bi bi-bar-chart-fill"></i></span>
                <div>
                    <div class="panel-title" id="officeDeviceChartTitle">Devices by Office</div>
                    <small>Current device distribution and connection status</small>
                </div>
            </div>
        </div>
        <?php if (empty($devicesByOfficeChart)): ?>
            <div class="dashboard-empty">No office device data is available.</div>
        <?php else: ?>
            <div class="office-device-chart-scroll">
                <div class="office-device-chart-body" style="--office-chart-columns: <?= count($devicesByOfficeChart) ?>">
                    <canvas
                        id="devicesByOfficeChart"
                        data-labels="<?= e(json_encode(array_column($devicesByOfficeChart, 'office'))) ?>"
                        data-online="<?= e(json_encode(array_map('intval', array_column($devicesByOfficeChart, 'online_devices')))) ?>"
                        data-network="<?= e(json_encode(array_map('intval', array_column($devicesByOfficeChart, 'network_devices')))) ?>"
                        data-unassigned="<?= e(json_encode(array_map('intval', array_column($devicesByOfficeChart, 'unassigned_devices')))) ?>"
                        data-disconnected="<?= e(json_encode(array_map('intval', array_column($devicesByOfficeChart, 'disconnected_devices')))) ?>"
                        aria-label="Devices by office chart"
                        role="img"
                    ></canvas>
                </div>
            </div>
            <div class="office-device-chart-legend" aria-label="Chart legend">
                <span><i class="is-online"></i> Computer</span>
                <span><i class="is-manual"></i> Network Device</span>
                <span><i class="is-disconnected"></i> Device Not Connect</span>
                <span><i class="is-unassigned"></i> Unassigned Device</span>
            </div>
        <?php endif; ?>
    </section>

</div>
