<?php
$osVersionText = static function (array $asset): string {
    $version = trim((string)($asset['os_version'] ?? ''));
    $build = trim((string)($asset['build_number'] ?? ''));
    if ($version !== '' && $build !== '') {
        return "Version {$version} (OS Build {$build})";
    }
    if ($version !== '') {
        return "Version {$version}";
    }
    if ($build !== '') {
        return "OS Build {$build}";
    }
    return '-';
};
$osNameText = static function (array $asset): string {
    return trim((string)($asset['os_name'] ?? '')) ?: '-';
};
$statusBadge = static function (array $asset): string {
    if (($asset['network_printer_state'] ?? '') === 'queued') return '<span class="badge text-bg-warning">Awaiting collector</span>';
    $online = !empty($asset['is_online']);
    $class = $online ? 'text-bg-success' : 'text-bg-secondary';
    $icon = $online ? 'bi-wifi' : 'bi-wifi-off';
    $label = $online ? 'Online' : 'Offline';
    return '<span class="badge ' . $class . '"><i class="bi ' . $icon . '"></i> ' . $label . '</span>';
};
$gbText = static function ($bytes): string {
    if ($bytes === null || $bytes === '' || !is_numeric($bytes) || (float)$bytes <= 0) {
        return '-';
    }
    $gb = (float)$bytes / 1073741824;
    $value = $gb >= 10 ? round($gb) : round($gb, 1);
    return number_format($value, $value == floor($value) ? 0 : 1) . ' GB';
};
$diskText = static function (?string $diskSummary) use ($gbText): string {
    if (!$diskSummary) {
        return '-';
    }
    $disks = json_decode($diskSummary, true);
    if (!is_array($disks)) {
        return '-';
    }
    $totalBytes = 0;
    foreach ($disks as $disk) {
        $totalBytes += (int)($disk['totalBytes'] ?? 0);
    }
    return $gbText($totalBytes);
};
$batteryHealthText = static function ($value): string {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '-';
    }
    return max(0, min(100, (int)round((float)$value))) . '%';
};
$cartridgeLevelText = static function (?string $details): string {
    $decoded = $details ? json_decode($details, true) : null;
    $supplies = is_array($decoded) && is_array($decoded['supplies'] ?? null) ? $decoded['supplies'] : [];
    if (!$supplies) return 'Unavailable';
    $readings = [];
    foreach ($supplies as $supply) {
        $name = trim((string)($supply['name'] ?? 'Cartridge')) ?: 'Cartridge';
        $percent = $supply['percent'] ?? null;
        $readings[] = $name . ': ' . ($percent === null || !is_numeric($percent) ? 'Unavailable' : max(0, min(100, (float)$percent)) . '%');
    }
    return implode(', ', $readings);
};
$dateText = static fn($value): string => af_date($value);
$dateTimeText = static fn($value): string => af_datetime($value);
?>

<div class="panel module-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-pc-display"></i></span>
            <div>
                <div class="panel-title mb-1">Offices</div>
                <small class="text-secondary">Select an office to manage its devices.</small>
            </div>
        </div>
        <?php if (Auth::can('assets.manage')): ?>
            <div class="d-flex flex-wrap gap-2">
            <?php if (Auth::can('offices.manage')): ?>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addNetworkPrinterModal"><i class="bi bi-router"></i> Add Network Device</button>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="module-body">
        <?php if (!$offices): ?>
            <div class="empty-state">
                <i class="bi bi-building"></i>
                <strong>No offices available</strong>
                <span>Device management records appear here after an office is available for your account.</span>
            </div>
        <?php else: ?>
            <div class="employee-table-toolbar">
                <div class="table-search table-search-lg" role="search">
                    <i class="bi bi-search"></i>
                    <input class="form-control" type="search" data-table-search="#officeSummaryTable" placeholder="Search office, device count, status">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-table-search-clear aria-label="Clear office search"><i class="bi bi-x-lg"></i></button>
                </div>
                <span class="employee-count-badge"><?= number_format(count($offices)) ?> office<?= count($offices) === 1 ? '' : 's' ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle data-table office-summary-table" id="officeSummaryTable" data-external-search="1">
                <thead>
                    <tr>
                        <th>Office</th>
                        <th class="text-center">Total Device</th>
                        <th class="text-center">Online</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($offices as $office): ?>
                    <tr>
                        <td data-label="Office"><strong><?= e($office['name']) ?></strong><br><small><?= e($office['address'] ?: '-') ?></small></td>
                        <td class="text-center" data-label="Total Device"><?= (int)$office['total_devices'] ?></td>
                        <td class="text-center" data-label="Online"><span class="badge text-bg-success"><?= (int)$office['online_devices'] ?></span></td>
                        <td class="text-end" data-label="Actions">
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#officeDevices<?= (int)$office['id'] ?>">
                                <i class="bi bi-pc-display"></i> Device Management
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (Auth::can('assets.manage') && Auth::can('offices.manage')): $networkCollectors = $networkCollectors ?? []; ?>
<div class="modal fade" id="addNetworkPrinterModal" tabindex="-1" aria-labelledby="addNetworkPrinterTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <form method="post" action="<?= $url('/device/network-devices') ?>">
            <?= Csrf::field() ?>
            <div class="modal-header"><h2 class="modal-title fs-5" id="addNetworkPrinterTitle">Add Network Device</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <?php if (!$networkCollectors): ?>
                    <div class="alert alert-warning">Enable an office collector in <a href="<?= $url('/settings') ?>">Settings</a> first. It needs agent 1.3.5 or newer and local network discovery enabled.</div>
                <?php else: ?>
                    <label class="form-label" for="networkPrinterIp">Network device IP address</label>
                    <input class="form-control" id="networkPrinterIp" name="address" type="text" inputmode="decimal" maxlength="15" placeholder="192.168.137.26" autocomplete="off" required>
                    <?php if (count($networkCollectors) === 1): ?>
                        <input type="hidden" name="collector_id" value="<?= (int)$networkCollectors[0]['id'] ?>">
                        <p class="small text-secondary mt-2">Collector: <?= e($networkCollectors[0]['computer_name']) ?></p>
                    <?php else: ?>
                        <label class="form-label mt-3" for="networkPrinterCollector">Collector that can reach this printer</label>
                        <select class="form-select" id="networkPrinterCollector" name="collector_id" required>
                            <option value="">Select collector</option>
                            <?php foreach ($networkCollectors as $collector): ?><option value="<?= (int)$collector['id'] ?>"><?= e($collector['computer_name'] . ' — ' . ($collector['office_name'] ?: 'Unassigned')) ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <p class="small text-secondary mt-3">The device will appear under Unassigned Device → Network Device. Use Assign to choose its employee and office. SNMP must be enabled; the collector identifies printers, switches, access points, and other network equipment automatically.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"<?= !$networkCollectors ? ' disabled' : '' ?>>Add Network Device</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php foreach ($offices as $office):
    $officeDevices = $devicesByOffice[(int)$office['id']] ?? [];
    $officeOnlineDevices = array_values(array_filter($officeDevices, static fn(array $device): bool =>
        empty($device['is_offline_device']) && empty($device['network_printer_id'])
    ));
    $officeNetworkPrinters = array_values(array_filter($officeDevices, static fn(array $device): bool =>
        !empty($device['network_printer_id'])
    ));
    $openOfficeDevice = array_values(array_filter($officeDevices, static fn(array $device): bool =>
        (int)$device['id'] === (int)($openDeviceId ?? 0)
    ))[0] ?? null;
    $openDeviceStatus = $openOfficeDevice && !empty($openOfficeDevice['network_printer_id'])
        ? 'printer'
        : 'online';
    $activeDeviceStatus = $openOfficeDevice ? $openDeviceStatus : 'online';
?>
<div class="modal" id="officeDevices<?= (int)$office['id'] ?>" tabindex="-1" aria-labelledby="officeDevicesLabel<?= (int)$office['id'] ?>" aria-hidden="true"<?= $openOfficeDevice ? ' data-auto-show-modal' : '' ?>>
    <div class="modal-dialog modal-fullscreen-xl-down office-devices-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="officeDevicesLabel<?= (int)$office['id'] ?>"><?= e($office['name']) ?> Device Management</h2>
                    <small class="text-secondary"><?= number_format(count($officeDevices)) ?> device<?= count($officeDevices) === 1 ? '' : 's' ?></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (!$officeDevices): ?>
                    <div class="empty-state">
                        <i class="bi bi-pc-display"></i>
                        <strong>No devices in this office</strong>
                        <span>Assigned devices for this office will appear here.</span>
                    </div>
                <?php else: ?>
                    <ul class="nav nav-tabs device-status-tabs" id="officeDeviceTabs<?= (int)$office['id'] ?>" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link<?= $activeDeviceStatus === 'online' ? ' active' : '' ?>" id="officeOnlineTab<?= (int)$office['id'] ?>" data-bs-toggle="tab" data-bs-target="#officeOnlineDevices<?= (int)$office['id'] ?>" type="button" role="tab" aria-controls="officeOnlineDevices<?= (int)$office['id'] ?>" aria-selected="<?= $activeDeviceStatus === 'online' ? 'true' : 'false' ?>">
                                Computers <span class="badge text-bg-success"><?= number_format(count($officeOnlineDevices)) ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link<?= $activeDeviceStatus === 'printer' ? ' active' : '' ?>" id="officePrinterTab<?= (int)$office['id'] ?>" data-bs-toggle="tab" data-bs-target="#officePrinterDevices<?= (int)$office['id'] ?>" type="button" role="tab" aria-controls="officePrinterDevices<?= (int)$office['id'] ?>" aria-selected="<?= $activeDeviceStatus === 'printer' ? 'true' : 'false' ?>">
                                Network Device <span class="badge text-bg-info"><?= number_format(count($officeNetworkPrinters)) ?></span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content device-status-content">
                        <?php foreach ([
                            ['online', 'Computers', $officeOnlineDevices],
                            ['printer', 'Network Device', $officeNetworkPrinters],
                        ] as [$deviceStatus, $deviceStatusLabel, $statusDevices]): ?>
                            <?php $statusPaneId = 'office' . ucfirst($deviceStatus) . 'Devices' . (int)$office['id']; ?>
                            <div class="tab-pane fade<?= $deviceStatus === $activeDeviceStatus ? ' show active' : '' ?>" id="<?= e($statusPaneId) ?>" role="tabpanel" aria-labelledby="office<?= ucfirst($deviceStatus) ?>Tab<?= (int)$office['id'] ?>" tabindex="0">
                                <?php if (!$statusDevices): ?>
                                    <div class="empty-state device-status-empty">
                                        <i class="bi <?= $deviceStatus === 'online' ? 'bi-wifi' : ($deviceStatus === 'printer' ? 'bi-printer' : 'bi-wifi-off') ?>"></i>
                                        <strong>No <?= e(strtolower($deviceStatusLabel)) ?> records</strong>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive device-table-wrap">
                                        <table class="table align-middle <?= $deviceStatus === 'online' ? 'device-detail-table' : 'offline-device-table' ?>">
                                            <thead>
                                                <?php if ($deviceStatus === 'printer'): ?>
                                                    <tr><th>Item</th><th>IP</th><th>Serial Number</th><th>Firmware Version</th><th>Cartridge Level</th><th>Status</th><th>Department</th><th>User</th><th>Actions</th></tr>
                                                <?php elseif ($deviceStatus === 'online'): ?>
                                                    <tr>
                                                        <th>Item</th>
                                                        <th>Serial</th>
                                                        <th>MAC</th>
                                                        <th>RAM</th>
                                                        <th>Disk</th>
                                                        <th>BIOS Version</th>
                                                        <th class="text-center">CPU</th>
                                                        <th class="text-center">Battery Health</th>
                                                        <th>Operating System</th>
                                                        <th>Device Name</th>
                                                        <th>User/Sector</th>
                                                        <th>Status</th>
                                                        <th>Maintenance</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                <?php else: ?>
                                                    <tr>
                                                        <th>Device Type</th>
                                                        <th>Brand</th>
                                                        <th>Model</th>
                                                        <th>Serial Number</th>
                                                        <th>Firmware</th>
                                                        <th>Assign User</th>
                                                        <th>Assign Office</th>
                                                        <th>Maintenance</th>
                                                        <th>Update Date</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                <?php endif; ?>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($statusDevices as $asset): ?>
                                                <?php $maintenance = $maintenanceByDevice[(int)$asset['id']] ?? []; ?>
                                                <?php $latestMaintenanceId = (int)($maintenance[0]['id'] ?? 0); ?>
                                                <?php $latestFirmwareMaintenance = array_values(array_filter($maintenance, static fn(array $change): bool => ($change['field_name'] ?? '') === 'firmware_version'))[0] ?? null; ?>
                                                <tr data-network-device-row="<?= (int)$asset['id'] ?>"<?= (int)$asset['id'] === (int)($openDeviceId ?? 0) ? ' class="is-open-device" data-open-device-row' : '' ?>>
                                                    <?php if ($deviceStatus === 'printer'): ?>
                                                        <td data-label="Item" data-network-field="item"><?= e($asset['model'] ?: $asset['device_name'] ?: 'Network Device') ?></td>
                                                        <td data-label="IP"><?= e($asset['network_printer_address'] ?: '-') ?></td>
                                                        <td data-label="Serial Number" data-network-field="serial"><?= e($asset['serial_number'] ?: '-') ?></td>
                                                        <td data-label="Firmware Version" data-network-field="firmware"><?= e($asset['firmware_version'] ?: 'Awaiting reading') ?></td>
                                                        <td data-label="Cartridge Level" data-network-field="supplies"><?= e($cartridgeLevelText($asset['network_printer_details'] ?? null)) ?></td>
                                                        <td data-label="Status" data-network-field="status"><?= $statusBadge($asset) ?></td>
                                                        <td data-label="Department"><?= e($asset['assigned_employee_department'] ?: '-') ?></td>
                                                        <td data-label="User"><?= e($asset['assigned_employee_name'] ?: '-') ?></td>
                                                    <?php elseif ($deviceStatus === 'online'): ?>
                                                        <td data-label="Item"><?= e(trim(($asset['manufacturer'] ?: '') . ' ' . ($asset['model'] ?: '')) ?: '-') ?><?php if (!empty($asset['network_printer_id'])): ?><br><span class="badge text-bg-info">Network Printer</span><br><small>IP: <?= e($asset['network_printer_address']) ?><br>Firmware: <?= e($asset['firmware_version'] ?: 'Awaiting reading') ?></small><?php endif; ?></td>
                                                        <td data-label="Serial"><?= e($asset['serial_number'] ?: '-') ?></td>
                                                        <td data-label="MAC"><?= e($asset['mac_address'] ?: ($asset['primary_mac_address'] ?? null) ?: '-') ?></td>
                                                        <td data-label="RAM"><?= e($gbText($asset['ram_bytes'] ?? null)) ?></td>
                                                        <td data-label="Disk"><?= e($diskText($asset['disk_summary'] ?? null)) ?></td>
                                                        <td data-label="BIOS Version"><?= e($asset['bios_version'] ?: '-') ?></td>
                                                        <td data-label="CPU"><?= e($asset['cpu_name'] ?: '-') ?></td>
                                                        <td class="text-center" data-label="Battery Health"><?= e($batteryHealthText($asset['battery_health_percent'] ?? null)) ?></td>
                                                        <td data-label="Operating System">
                                                            <span class="device-os-line"><?= e($osNameText($asset)) ?></span>
                                                            <span class="device-os-line text-secondary"><?= e($osVersionText($asset)) ?></span>
                                                        </td>
                                                        <td data-label="Device Name"><?= e($asset['device_name'] ?: $asset['computer_name'] ?: '-') ?></td>
                                                        <td data-label="User/Sector">
                                                            <strong><?= e($asset['assigned_employee_name'] ?: '-') ?></strong><br>
                                                            <small class="text-secondary"><?= e($asset['assigned_employee_designation'] ?: '-') ?><?= !empty($asset['assigned_employee_department']) ? ' / ' . e($asset['assigned_employee_department']) : '' ?></small>
                                                        </td>
                                                        <td data-label="Status"><?= $statusBadge($asset) ?></td>
                                                        <td data-label="Maintenance">
                                                            <button class="maintenance-link<?= $maintenance ? ' is-alert' : '' ?>" type="button" data-maintenance-link data-device-id="<?= (int)$asset['id'] ?>" data-latest-maintenance-id="<?= $latestMaintenanceId ?>" data-child-modal-target="#deviceMaintenance<?= (int)$asset['id'] ?>">
                                                                Maintenance Details
                                                            </button>
                                                        </td>
                                                    <?php else: ?>
                                                        <td data-label="Device Type"><?= e($asset['device_type'] ?: '-') ?></td>
                                                        <td data-label="Brand"><?= e($asset['manufacturer'] ?: '-') ?></td>
                                                        <td data-label="Model"><?= e($asset['model'] ?: '-') ?></td>
                                                        <td data-label="Serial Number"><?= e($asset['serial_number'] ?: '-') ?></td>
                                                        <td data-label="Firmware"><?= e($asset['firmware_version'] ?: '-') ?></td>
                                                        <td data-label="Assign User">
                                                            <strong><?= e($asset['assigned_employee_name'] ?: '-') ?></strong><br>
                                                            <small class="text-secondary"><?= e($asset['assigned_employee_designation'] ?: '-') ?><?= !empty($asset['assigned_employee_department']) ? ' / ' . e($asset['assigned_employee_department']) : '' ?></small>
                                                        </td>
                                                        <td data-label="Assign Office"><?= e($asset['office_name'] ?: '-') ?></td>
                                                        <td data-label="Maintenance">
                                                            <button class="maintenance-link<?= $latestFirmwareMaintenance ? ' is-alert' : '' ?>" type="button" data-maintenance-link data-device-id="<?= (int)$asset['id'] ?>" data-latest-maintenance-id="<?= (int)($latestFirmwareMaintenance['id'] ?? 0) ?>" data-child-modal-target="#deviceMaintenance<?= (int)$asset['id'] ?>">
                                                                Maintenance Details
                                                            </button>
                                                        </td>
                                                        <td data-label="Update Date"><?= e($dateTimeText($latestFirmwareMaintenance['changed_at'] ?? null)) ?></td>
                                                    <?php endif; ?>
                                                    <td class="text-end" data-label="Actions">
                                                        <div class="dropdown device-actions">
                                                            <button class="btn btn-sm btn-outline-secondary device-actions-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Device actions"><i class="bi bi-three-dots-vertical"></i></button>
                                                            <div class="dropdown-menu dropdown-menu-end device-actions-menu">
                                                                <?php if ($deviceStatus === 'printer' && Auth::can('assets.manage')): ?>
                                                                    <form method="post" action="<?= $url('/device/network-device-sync') ?>" data-network-device-sync-form>
                                                                        <?= Csrf::field() ?>
                                                                        <input type="hidden" name="device_id" value="<?= (int)$asset['id'] ?>">
                                                                        <button class="dropdown-item" type="submit"><i class="bi bi-arrow-repeat"></i> Sync</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <?php if ($deviceStatus === 'online' && empty($asset['network_printer_id'])): ?>
                                                                    <button class="dropdown-item" type="button" data-child-modal-target="#deviceSoftware<?= (int)$asset['id'] ?>">Installed Software</button>
                                                                <?php elseif ($deviceStatus === 'offline' && Auth::can('assets.manage')): ?>
                                                                    <button class="dropdown-item" type="button" data-child-modal-target="#editOfflineDevice<?= (int)$asset['id'] ?>">Edit</button>
                                                                <?php endif; ?>
                                                                <?php if (Auth::can('offices.manage')): ?>
                                                                    <button class="dropdown-item" type="button" data-child-modal-target="#changeDeviceOffice<?= (int)$asset['id'] ?>">Change Office</button>
                                                                    <form method="post" action="<?= $url('/device/unassign-office') ?>" data-confirm="Move this device to Unassigned?" data-confirm-action="remove">
                                                                        <?= Csrf::field() ?>
                                                                        <input type="hidden" name="device_id" value="<?= (int)$asset['id'] ?>">
                                                                        <button class="dropdown-item" type="submit">Remove</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <button class="dropdown-item text-danger" type="button" data-delete-request data-entity-type="device" data-entity-id="<?= (int)$asset['id'] ?>" data-entity-label="<?= e($asset['device_name'] ?: $asset['computer_name'] ?: 'Device') ?>"><i class="bi bi-trash"></i> Request deletion</button>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php foreach ($officeDevices as $asset):
    $software = $softwareByDevice[(int)$asset['id']] ?? [];
    $drivers = $driversByDevice[(int)$asset['id']] ?? [];
    $softwareUpdates = $softwareUpdatesByDevice[(int)$asset['id']] ?? [];
    $latestSoftwareUpdateId = (int)($softwareUpdates[0]['id'] ?? 0);
    $driverUpdates = $driverUpdatesByDevice[(int)$asset['id']] ?? [];
    $latestDriverUpdateId = (int)($driverUpdates[0]['id'] ?? 0);
    $maintenance = $maintenanceByDevice[(int)$asset['id']] ?? [];
    if (empty($asset['agent_token_hash']) || !empty($asset['agent_uninstalled_at'])) {
        $maintenance = array_values(array_filter($maintenance, static fn(array $change): bool => ($change['field_name'] ?? '') === 'firmware_version'));
    }
    $itemName = trim(($asset['manufacturer'] ?: '') . ' ' . ($asset['model'] ?: '')) ?: ($asset['device_name'] ?: $asset['computer_name']);
    $softwareUser = $asset['assigned_employee_name'] ?: 'Unassigned user';
    $softwareDesignation = $asset['assigned_employee_designation'] ?: 'No designation';
    $softwareOffice = $asset['office_name'] ?: 'Unassigned office';
?>
<?php if (Auth::can('offices.manage')): ?>
<div class="modal fade" id="changeDeviceOffice<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="changeDeviceOfficeLabel<?= (int)$asset['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/device/change-assignment') ?>" data-change-assignment-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="device_id" value="<?= (int)$asset['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="changeDeviceOfficeLabel<?= (int)$asset['id'] ?>">Change Office</h2>
                        <small class="text-secondary"><?= e($itemName) ?></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body compact-form">
                    <div>
                        <label class="form-label">Office</label>
                        <select class="form-select" name="office_id" data-change-office-select required>
                            <option value="">Select office</option>
                            <?php foreach ($offices as $optionOffice): ?>
                                <option value="<?= (int)$optionOffice['id'] ?>" <?= (int)$asset['office_id'] === (int)$optionOffice['id'] ? 'selected' : '' ?>><?= e($optionOffice['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">User</label>
                        <select class="form-select assignment-native-select" name="assigned_employee_id" data-change-employee-select data-searchable-assignment-select data-search-placeholder="Search employee name or ID">
                            <option value="">Unassigned user</option>
                            <?php foreach ($employees as $employee):
                                $employeeNumber = trim((string)($employee['employee_number'] ?? ''));
                                $employeeLabel = $employee['name'] . ' - ' . ($employeeNumber !== '' ? $employeeNumber : 'No ID');
                                $employeeSearch = trim((string)$employee['name'] . ' ' . $employeeNumber);
                            ?>
                                <option value="<?= (int)$employee['id'] ?>" data-office-id="<?= (int)$employee['office_id'] ?>" data-search="<?= e($employeeSearch) ?>" <?= (int)($asset['assigned_employee_id'] ?? 0) === (int)$employee['id'] ? 'selected' : '' ?>><?= e($employeeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">Only users from the selected office are available.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="bi bi-check2"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (false): // Legacy manual-device editor intentionally retired. ?>
<div class="modal fade" id="editOfflineDevice<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="editOfflineDeviceLabel<?= (int)$asset['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= $url('/device/update') ?>" data-office-scoped-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$asset['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="editOfflineDeviceLabel<?= (int)$asset['id'] ?>">Edit Offline Device</h2>
                        <small class="text-secondary"><?= e($itemName) ?></small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Device Type</label>
                            <select class="form-select" name="device_type">
                                <?php foreach (['Printer', 'Scanner', 'Copier Machine', 'Access Point', 'Wireless Router', 'Switch', 'Firewall', 'NVR', 'DVR', 'Security Camera', 'Bridge', 'Repeater', 'Modem', 'Other'] as $type): ?>
                                    <option <?= ($asset['device_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Brand</label>
                            <input class="form-control" name="manufacturer" value="<?= e($asset['manufacturer'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model</label>
                            <input class="form-control" name="model" value="<?= e($asset['model'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Serial Number</label>
                            <input class="form-control" name="serial_number" value="<?= e($asset['serial_number'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Firmware</label>
                            <input class="form-control" name="firmware_version" value="<?= e($asset['firmware_version'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign Office</label>
                            <select class="form-select" name="office_id" data-office-source-select required>
                                <option value="">Select office</option>
                                <?php foreach ($offices as $optionOffice): ?>
                                    <option value="<?= (int)$optionOffice['id'] ?>" <?= (int)($asset['office_id'] ?? 0) === (int)$optionOffice['id'] ? 'selected' : '' ?>><?= e($optionOffice['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign User</label>
                            <select class="form-select assignment-native-select" name="assigned_employee_id" data-office-scoped-select data-searchable-assignment-select data-search-placeholder="Search employee, designation, or office">
                                <option value="">Unassigned</option>
                                <?php foreach ($employees as $employee):
                                    $employeeLabel = $employee['name'];
                                    if (!empty($employee['designation'])) {
                                        $employeeLabel .= ' - ' . $employee['designation'];
                                    }
                                    if (!empty($employee['office_name'])) {
                                        $employeeLabel .= ' - ' . $employee['office_name'];
                                    }
                                    $employeeSearch = trim(implode(' ', array_filter([
                                        $employee['name'] ?? null,
                                        $employee['designation'] ?? null,
                                        $employee['office_name'] ?? null,
                                    ])));
                                ?>
                                    <option value="<?= (int)$employee['id'] ?>" data-office-id="<?= (int)$employee['office_id'] ?>" data-search="<?= e($employeeSearch) ?>" <?= (int)($asset['assigned_employee_id'] ?? 0) === (int)$employee['id'] ? 'selected' : '' ?>><?= e($employeeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="bi bi-check2"></i> Save Device</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal" id="deviceMaintenance<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="deviceMaintenanceLabel<?= (int)$asset['id'] ?>" aria-hidden="true" data-device-id="<?= (int)$asset['id'] ?>">
    <div class="modal-dialog maintenance-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header maintenance-modal-header">
                <div class="maintenance-modal-title-wrap">
                    <span class="maintenance-modal-icon"><i class="bi bi-tools"></i></span>
                    <div>
                        <h2 class="modal-title fs-5" id="deviceMaintenanceLabel<?= (int)$asset['id'] ?>">Maintenance Details</h2>
                        <small class="text-secondary"><?= e($itemName) ?></small>
                    </div>
                </div>
                <?php if ($maintenance): ?>
                    <span class="maintenance-count-badge"><?= number_format(count($maintenance)) ?> change<?= count($maintenance) === 1 ? '' : 's' ?></span>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-maintenance-body>
                <?php if (!$maintenance): ?>
                    <div class="empty-state">
                        <i class="bi bi-tools"></i>
                        <strong>No maintenance changes</strong>
                        <span>RAM, hard drive, BIOS, and OS changes will appear after future agent inventory uploads.</span>
                    </div>
                <?php else: ?>
                    <?php if (Auth::can('assets.manage')): ?>
                        <form id="maintenanceBulkDelete<?= (int)$asset['id'] ?>" class="bulk-delete-toolbar" method="post" action="<?= $url('/device/maintenance/delete') ?>" data-confirm="Delete selected maintenance records?" data-maintenance-bulk-delete-form>
                            <?= Csrf::field() ?>
                            <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                                <i class="bi bi-trash"></i> Delete selected
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="table-responsive maintenance-table-wrap">
                        <table class="table align-middle maintenance-table">
                            <thead><tr><?php if (Auth::can('assets.manage')): ?><th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all maintenance records" data-bulk-select-all data-bulk-target="maintenanceBulkDelete<?= (int)$asset['id'] ?>"></th><?php endif; ?><th>Change</th><th>Previous</th><th>Current</th><th>Date</th><?php if (Auth::can('assets.manage')): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($maintenance as $change): ?>
                                <tr data-maintenance-id="<?= (int)$change['id'] ?>">
                                    <?php if (Auth::can('assets.manage')): ?>
                                        <td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$change['id'] ?>" form="maintenanceBulkDelete<?= (int)$asset['id'] ?>" aria-label="Select maintenance record" data-bulk-select-item></td>
                                    <?php endif; ?>
                                    <td data-label="Change"><strong><?= e($change['display_name'] ?: $change['field_name']) ?></strong></td>
                                    <td data-label="Previous"><?= e($change['previous_value'] ?: '-') ?></td>
                                    <td data-label="Current"><?= e($change['current_value'] ?: '-') ?></td>
                                    <td data-label="Date"><?= e($dateTimeText($change['changed_at'] ?? null)) ?></td>
                                    <?php if (Auth::can('assets.manage')): ?>
                                        <td class="text-end" data-label="Actions">
                                            <form method="post" action="<?= $url('/device/maintenance/delete') ?>" data-confirm="Delete this maintenance record?" data-maintenance-delete-form>
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$change['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger maintenance-delete-btn" type="submit" title="Delete record" aria-label="Delete maintenance record">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="modal" id="deviceSoftware<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="deviceSoftwareLabel<?= (int)$asset['id'] ?>" aria-hidden="true">
    <div class="modal-dialog software-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="deviceSoftwareLabel<?= (int)$asset['id'] ?>"><?= e($itemName) ?></h2>
                    <small class="text-secondary software-owner-meta"><?= e($softwareUser) ?> / <?= e($softwareDesignation) ?> / <?= e($softwareOffice) ?></small>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button class="btn btn-sm btn-outline-secondary software-update-trigger<?= $softwareUpdates ? ' is-alert' : '' ?>" type="button" data-child-modal-target="#deviceSoftwareUpdates<?= (int)$asset['id'] ?>" data-software-update-trigger data-device-id="<?= (int)$asset['id'] ?>" data-latest-software-update-id="<?= $latestSoftwareUpdateId ?>">
                        <i class="bi bi-clock-history"></i> Software Updates <span data-software-update-count><?= number_format(count($softwareUpdates)) ?></span>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary software-update-trigger<?= $driverUpdates ? ' is-alert' : '' ?>" type="button" data-child-modal-target="#deviceDriverUpdates<?= (int)$asset['id'] ?>" data-driver-update-trigger data-device-id="<?= (int)$asset['id'] ?>" data-latest-driver-update-id="<?= $latestDriverUpdateId ?>">
                        <i class="bi bi-clock-history"></i> Driver Updates <span data-driver-update-count><?= number_format(count($driverUpdates)) ?></span>
                    </button>
                    <form method="post" action="<?= $url('/device/software-sync') ?>" data-driver-sync-url="<?= $url('/device/driver-sync') ?>" data-inventory-sync-form>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="device_id" value="<?= (int)$asset['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary" type="submit">
                            <i class="bi bi-arrow-repeat"></i> Sync
                        </button>
                    </form>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="deviceInventoryTabs<?= (int)$asset['id'] ?>" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="deviceSoftwareTab<?= (int)$asset['id'] ?>" data-bs-toggle="tab" data-bs-target="#deviceSoftwarePane<?= (int)$asset['id'] ?>" type="button" role="tab" aria-controls="deviceSoftwarePane<?= (int)$asset['id'] ?>" aria-selected="true">
                            Software <span class="badge text-bg-secondary ms-1" data-software-tab-count><?= number_format(count($software)) ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="deviceDriversTab<?= (int)$asset['id'] ?>" data-bs-toggle="tab" data-bs-target="#deviceDriversPane<?= (int)$asset['id'] ?>" type="button" role="tab" aria-controls="deviceDriversPane<?= (int)$asset['id'] ?>" aria-selected="false">
                            Drivers <span class="badge text-bg-secondary ms-1" data-driver-tab-count><?= number_format(count($drivers)) ?></span>
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="deviceSoftwarePane<?= (int)$asset['id'] ?>" role="tabpanel" aria-labelledby="deviceSoftwareTab<?= (int)$asset['id'] ?>" tabindex="0">
                        <?php if (!$software): ?>
                            <div class="empty-state">
                                <i class="bi bi-box-seam"></i>
                                <strong>No software records</strong>
                                <span>Installed software will appear after the agent uploads inventory.</span>
                            </div>
                        <?php else: ?>
                            <div class="software-panel">
                                <div class="software-toolbar">
                                    <div class="software-search">
                                        <i class="bi bi-search"></i>
                                        <input class="form-control" type="search" data-software-search="#softwareTable<?= (int)$asset['id'] ?>" placeholder="Search software, publisher, version">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-software-clear aria-label="Clear software search"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                    <span class="software-count" data-software-count><?= number_format(count($software)) ?> shown</span>
                                </div>
                                <div class="table-responsive software-table-wrap">
                                    <table class="table align-middle software-table" id="softwareTable<?= (int)$asset['id'] ?>">
                                        <thead><tr><th>Name</th><th>Version</th><th>Publisher</th><th>Installed</th><th>Updated</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($software as $item): ?>
                                            <tr>
                                                <td data-label="Name"><?= e($item['name'] ?: '-') ?></td>
                                                <td data-label="Version"><?= e($item['version'] ?: '-') ?></td>
                                                <td data-label="Publisher"><?= e($item['publisher'] ?: '-') ?></td>
                                                <td data-label="Installed"><?= e($dateText($item['installed_date'] ?? null)) ?></td>
                                                <td data-label="Updated"><?= e($dateText($item['last_updated_date'] ?? null)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div class="software-empty-search text-secondary d-none">No software matches your search.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="tab-pane fade" id="deviceDriversPane<?= (int)$asset['id'] ?>" role="tabpanel" aria-labelledby="deviceDriversTab<?= (int)$asset['id'] ?>" tabindex="0">
                        <?php if (!$drivers): ?>
                            <div class="empty-state">
                                <i class="bi bi-cpu"></i>
                                <strong>No driver records</strong>
                                <span>Installed drivers will appear after the agent uploads inventory.</span>
                            </div>
                        <?php else: ?>
                            <div class="software-panel driver-panel">
                                <div class="software-toolbar">
                                    <div class="software-search">
                                        <i class="bi bi-search"></i>
                                        <input class="form-control" type="search" data-software-search="#driverTable<?= (int)$asset['id'] ?>" placeholder="Search device, provider, version">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-software-clear aria-label="Clear driver search"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                    <span class="software-count" data-software-count><?= number_format(count($drivers)) ?> shown</span>
                                </div>
                                <div class="table-responsive software-table-wrap driver-table-wrap">
                                    <table class="table align-middle software-table driver-table" id="driverTable<?= (int)$asset['id'] ?>">
                                        <thead><tr><th>Device</th><th>Version</th><th>Provider</th><th>Class</th><th>Installed</th><th>Updated</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($drivers as $driver): ?>
                                            <tr>
                                                <td data-label="Device"><?= e($driver['device_name'] ?: '-') ?></td>
                                                <td data-label="Version"><?= e($driver['version'] ?: '-') ?></td>
                                                <td data-label="Provider"><?= e($driver['provider'] ?: ($driver['manufacturer'] ?: '-')) ?></td>
                                                <td data-label="Class"><?= e($driver['device_class'] ?: '-') ?></td>
                                                <td data-label="Installed"><?= e($dateText($driver['installed_date'] ?? null)) ?></td>
                                                <td data-label="Updated"><?= e($dateText($driver['last_updated_date'] ?? null)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div class="software-empty-search text-secondary d-none">No drivers match your search.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal" id="deviceSoftwareUpdates<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="deviceSoftwareUpdatesLabel<?= (int)$asset['id'] ?>" aria-hidden="true" data-bs-backdrop="static" data-device-id="<?= (int)$asset['id'] ?>" data-software-update-delete-url="<?= $url('/device/software-updates/delete') ?>" data-can-delete-software-update="<?= Auth::can('assets.manage') ? '1' : '0' ?>">
    <div class="modal-dialog software-update-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header software-update-modal-header">
                <div class="software-update-title-wrap">
                    <span class="software-update-icon"><i class="bi bi-arrow-up-circle"></i></span>
                    <div>
                        <h2 class="modal-title fs-5" id="deviceSoftwareUpdatesLabel<?= (int)$asset['id'] ?>">Software Updates</h2>
                        <small class="text-secondary"><?= e($itemName) ?></small>
                    </div>
                </div>
                <span class="software-update-badge" data-software-update-total><?= number_format(count($softwareUpdates)) ?> update<?= count($softwareUpdates) === 1 ? '' : 's' ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-software-update-body>
                <?php if (!$softwareUpdates): ?>
                    <div class="empty-state">
                        <i class="bi bi-clock-history"></i>
                        <strong>No software updates</strong>
                        <span>Version changes will appear here after future software inventory uploads.</span>
                    </div>
                <?php else: ?>
                    <?php if (Auth::can('assets.manage')): ?>
                        <form id="softwareUpdateBulkDelete<?= (int)$asset['id'] ?>" class="bulk-delete-toolbar" method="post" action="<?= $url('/device/software-updates/delete') ?>" data-confirm="Delete selected software update records?" data-software-update-bulk-delete-form>
                            <?= Csrf::field() ?>
                            <label class="bulk-select-all-label">
                                <input class="form-check-input" type="checkbox" aria-label="Select all software update records" data-bulk-select-all data-bulk-target="softwareUpdateBulkDelete<?= (int)$asset['id'] ?>">
                                Select all
                            </label>
                            <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                                <i class="bi bi-trash"></i> Delete selected
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="table-responsive software-update-table-wrap">
                        <table class="table align-middle software-update-table">
                            <thead><tr><?php if (Auth::can('assets.manage')): ?><th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all software update records" data-bulk-select-all data-bulk-target="softwareUpdateBulkDelete<?= (int)$asset['id'] ?>"></th><?php endif; ?><th>Software</th><th>Old Version</th><th>New Version</th><th>Update Date</th><?php if (Auth::can('assets.manage')): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($softwareUpdates as $update): ?>
                                <tr data-software-update-id="<?= (int)$update['id'] ?>">
                                    <?php if (Auth::can('assets.manage')): ?>
                                        <td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$update['id'] ?>" form="softwareUpdateBulkDelete<?= (int)$asset['id'] ?>" aria-label="Select software update record" data-bulk-select-item></td>
                                    <?php endif; ?>
                                    <td data-label="Software"><strong><?= e($update['software_name'] ?: '-') ?></strong></td>
                                    <td data-label="Old Version"><?= e($update['previous_version'] ?: '-') ?></td>
                                    <td data-label="New Version"><?= e($update['current_version'] ?: '-') ?></td>
                                    <td data-label="Update Date"><?= e($dateTimeText($update['created_at'] ?? null)) ?></td>
                                    <?php if (Auth::can('assets.manage')): ?>
                                        <td class="text-end" data-label="Actions">
                                            <form method="post" action="<?= $url('/device/software-updates/delete') ?>" data-confirm="Delete this software update record?" data-software-update-delete-form>
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$update['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger software-update-delete-btn" type="submit" title="Delete record" aria-label="Delete software update record">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="modal" id="deviceDriverUpdates<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="deviceDriverUpdatesLabel<?= (int)$asset['id'] ?>" aria-hidden="true" data-bs-backdrop="static" data-device-id="<?= (int)$asset['id'] ?>" data-driver-update-delete-url="<?= $url('/device/driver-updates/delete') ?>" data-can-delete-driver-update="<?= Auth::can('assets.manage') ? '1' : '0' ?>">
    <div class="modal-dialog software-update-modal modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header software-update-modal-header">
                <div class="software-update-title-wrap">
                    <span class="software-update-icon"><i class="bi bi-arrow-up-circle"></i></span>
                    <div>
                        <h2 class="modal-title fs-5" id="deviceDriverUpdatesLabel<?= (int)$asset['id'] ?>">Driver Updates</h2>
                        <small class="text-secondary"><?= e($itemName) ?></small>
                    </div>
                </div>
                <span class="software-update-badge" data-driver-update-total><?= number_format(count($driverUpdates)) ?> update<?= count($driverUpdates) === 1 ? '' : 's' ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" data-driver-update-body>
                <?php if (!$driverUpdates): ?>
                    <div class="empty-state">
                        <i class="bi bi-clock-history"></i>
                        <strong>No driver updates</strong>
                        <span>Version changes will appear here after future driver inventory uploads.</span>
                    </div>
                <?php else: ?>
                    <?php if (Auth::can('assets.manage')): ?>
                        <form id="driverUpdateBulkDelete<?= (int)$asset['id'] ?>" class="bulk-delete-toolbar" method="post" action="<?= $url('/device/driver-updates/delete') ?>" data-confirm="Delete selected driver update records?" data-driver-update-bulk-delete-form>
                            <?= Csrf::field() ?>
                            <label class="bulk-select-all-label">
                                <input class="form-check-input" type="checkbox" aria-label="Select all driver update records" data-bulk-select-all data-bulk-target="driverUpdateBulkDelete<?= (int)$asset['id'] ?>">
                                Select all
                            </label>
                            <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                                <i class="bi bi-trash"></i> Delete selected
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="table-responsive software-update-table-wrap">
                        <table class="table align-middle software-update-table">
                            <thead><tr><?php if (Auth::can('assets.manage')): ?><th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all driver update records" data-bulk-select-all data-bulk-target="driverUpdateBulkDelete<?= (int)$asset['id'] ?>"></th><?php endif; ?><th>Driver</th><th>Old Version</th><th>New Version</th><th>Update Date</th><?php if (Auth::can('assets.manage')): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($driverUpdates as $update): ?>
                                <tr data-driver-update-id="<?= (int)$update['id'] ?>">
                                    <?php if (Auth::can('assets.manage')): ?>
                                        <td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$update['id'] ?>" form="driverUpdateBulkDelete<?= (int)$asset['id'] ?>" aria-label="Select driver update record" data-bulk-select-item></td>
                                    <?php endif; ?>
                                    <td data-label="Driver"><strong><?= e($update['device_name'] ?: '-') ?></strong></td>
                                    <td data-label="Old Version"><?= e($update['previous_version'] ?: '-') ?></td>
                                    <td data-label="New Version"><?= e($update['current_version'] ?: '-') ?></td>
                                    <td data-label="Update Date"><?= e($dateTimeText($update['created_at'] ?? null)) ?></td>
                                    <?php if (Auth::can('assets.manage')): ?>
                                        <td class="text-end" data-label="Actions">
                                            <form method="post" action="<?= $url('/device/driver-updates/delete') ?>" data-confirm="Delete this driver update record?" data-driver-update-delete-form>
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$update['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger software-update-delete-btn" type="submit" title="Delete record" aria-label="Delete driver update record">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-labelledby="deleteRequestModalLabel" aria-hidden="true" data-delete-request-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/delete-requests/store') ?>" data-delete-request-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="entity_type" data-delete-request-type>
                <input type="hidden" name="redirect_to" value="/device">
                <div data-delete-request-ids></div>
                <div class="modal-header"><div><h2 class="modal-title fs-5" id="deleteRequestModalLabel"><i class="bi bi-trash"></i> Request Deletion</h2><small class="text-secondary">An authorized user must approve this action.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body compact-form">
                    <div class="alert alert-warning mb-0">You are requesting deletion of <strong data-delete-request-label>the selected device</strong>.</div>
                    <label class="form-label">Assign approval to
                        <select class="form-select" name="assigned_to_user_id" required <?= empty($deleteApprovers) ? 'disabled' : '' ?>><option value="">Select an authorized user</option><?php foreach ($deleteApprovers as $approver): ?><option value="<?= (int)$approver['id'] ?>"><?= e($approver['name']) ?><?= !empty($approver['office_name']) ? ' - ' . e($approver['office_name']) : ' - Sysadmin' ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="form-label">Reason (optional)<textarea class="form-control" name="request_note" rows="3" maxlength="1000" placeholder="Explain why this device should be deleted"></textarea></label>
                    <?php if (empty($deleteApprovers)): ?><div class="alert alert-danger mb-0">No authorized approver is currently available. Contact the Sysadmin.</div><?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit" <?= empty($deleteApprovers) ? 'disabled' : '' ?>><i class="bi bi-send"></i> Send Request</button></div>
            </form>
        </div>
    </div>
</div>
