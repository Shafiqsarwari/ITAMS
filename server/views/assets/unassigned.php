<?php
$statusBadge = static function (array $device): string {
    if (($device['network_printer_state'] ?? '') === 'queued') return '<span class="badge text-bg-warning">Awaiting collector</span>';
    $online = !empty($device['is_online']);
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
$cartridgeLevelText = static function (?string $details): string {
    if (!$details) return 'Unavailable';
    $decoded = json_decode($details, true);
    $supplies = is_array($decoded) ? ($decoded['supplies'] ?? []) : [];
    if (!is_array($supplies) || !$supplies) return 'Unavailable';
    $readings = [];
    foreach ($supplies as $supply) {
        if (!is_array($supply)) continue;
        $name = trim((string)($supply['name'] ?? 'Cartridge')) ?: 'Cartridge';
        $percent = $supply['percent'] ?? null;
        $readings[] = $name . ': ' . ($percent === null || !is_numeric($percent) ? 'Unavailable' : max(0, min(100, (float)$percent)) . '%');
    }
    return $readings ? implode(', ', $readings) : 'Unavailable';
};
$onlineDevices = array_values(array_filter($devices, static fn(array $device): bool =>
    empty($device['network_printer_id']) && !empty($device['agent_token_hash'])
));
$networkDevices = array_values(array_filter($devices, static fn(array $device): bool =>
    !empty($device['network_printer_id'])
));
$activeDeviceStatus = ($activeDeviceStatus ?? 'online') === 'network' ? 'network' : 'online';
?>

<div class="panel unassigned-workbench">
    <div class="unassigned-header">
        <div class="unassigned-heading">
            <span class="unassigned-icon"><i class="bi bi-inboxes"></i></span>
            <div>
                <div class="panel-title mb-1">Unassigned Device</div>
                <small class="text-secondary">Devices waiting for office assignment.</small>
            </div>
        </div>
        <div class="unassigned-counts" aria-label="Unassigned device counts">
            <span><strong><?= number_format(count($devices)) ?></strong> Total</span>
            <span class="is-online"><strong><?= number_format(count($onlineDevices)) ?></strong> Online</span>
            <span class="is-offline"><strong><?= number_format(count($networkDevices)) ?></strong> Network</span>
        </div>
    </div>

    <?php if (!$employees): ?>
        <div class="alert alert-warning mb-0">Create an employee with an office before assigning devices.</div>
    <?php elseif (!$devices): ?>
        <div class="empty-state">
            <i class="bi bi-check-circle"></i>
            <strong>No unassigned device records</strong>
            <span>New and removed devices without an office will appear here.</span>
        </div>
    <?php else: ?>
        <ul class="nav nav-tabs device-status-tabs unassigned-status-tabs" id="unassignedDeviceTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeDeviceStatus === 'online' ? ' active' : '' ?>" id="unassignedOnlineTab" data-bs-toggle="tab" data-bs-target="#unassignedOnlineDevices" type="button" role="tab" aria-controls="unassignedOnlineDevices" aria-selected="<?= $activeDeviceStatus === 'online' ? 'true' : 'false' ?>">
                    Computers <span class="badge text-bg-success"><?= number_format(count($onlineDevices)) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeDeviceStatus === 'network' ? ' active' : '' ?>" id="unassignedNetworkTab" data-bs-toggle="tab" data-bs-target="#unassignedNetworkDevices" type="button" role="tab" aria-controls="unassignedNetworkDevices" aria-selected="<?= $activeDeviceStatus === 'network' ? 'true' : 'false' ?>">
                    Network Device <span class="badge text-bg-info"><?= number_format(count($networkDevices)) ?></span>
                </button>
            </li>
        </ul>
        <div class="tab-content device-status-content">
            <?php foreach ([
                ['online', 'Computers', $onlineDevices],
                ['network', 'Network Device', $networkDevices],
            ] as [$deviceStatus, $deviceStatusLabel, $statusDevices]): ?>
                <div class="tab-pane fade<?= $deviceStatus === $activeDeviceStatus ? ' show active' : '' ?>" id="unassigned<?= ucfirst($deviceStatus) ?>Devices" role="tabpanel" aria-labelledby="unassigned<?= ucfirst($deviceStatus) ?>Tab" tabindex="0">
                    <?php if (!$statusDevices): ?>
                        <div class="empty-state device-status-empty">
                            <i class="bi <?= $deviceStatus === 'online' ? 'bi-wifi' : 'bi-router' ?>"></i>
                            <strong>No <?= e(strtolower($deviceStatusLabel)) ?> records</strong>
                        </div>
                    <?php else: ?>
                            <form id="unassigned<?= ucfirst($deviceStatus) ?>BulkDelete" class="bulk-delete-toolbar">
                                <button class="btn btn-sm btn-outline-danger" type="button" disabled data-bulk-delete-submit data-delete-request data-entity-type="device" data-bulk-source="unassigned<?= ucfirst($deviceStatus) ?>BulkDelete" data-entity-label="selected devices">
                                    <i class="bi bi-trash"></i> Request deletion
                                </button>
                            </form>
                        <div class="table-responsive device-table-wrap unassigned-table-wrap">
                            <table class="table align-middle device-detail-table <?= $deviceStatus === 'online' ? 'unassigned-online-table' : 'unassigned-network-table' ?>">
                                <thead>
                                    <?php if ($deviceStatus === 'network'): ?>
                                        <tr>
                                            <th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all network devices" data-bulk-select-all data-bulk-target="unassignedNetworkBulkDelete"></th>
                                            <th class="network-item-col">Item</th><th class="network-ip-col">IP</th><th class="network-serial-col">Serial Number</th><th class="network-firmware-col">Firmware Version</th><th class="network-cartridge-col">Cartridge Level</th><th class="network-status-col text-center">Status</th><th>Department</th><th>User</th><th class="network-actions-col text-center">Actions</th>
                                        </tr>
                                    <?php elseif ($deviceStatus === 'online'): ?>
                                        <tr>
                                            <th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all <?= e(strtolower($deviceStatusLabel)) ?>" data-bulk-select-all data-bulk-target="unassigned<?= ucfirst($deviceStatus) ?>BulkDelete"></th>
                                            <th>Item</th>
                                            <th>Serial</th>
                                            <th>MAC</th>
                                            <th>RAM</th>
                                            <th>Disk</th>
                                            <th>BIOS Version</th>
                                            <th class="device-cpu-col text-center">CPU</th>
                                            <th class="text-center device-battery-col">Battery Health</th>
                                            <th>Operating System</th>
                                            <th>Device Name</th>
                                            <th>User</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all <?= e(strtolower($deviceStatusLabel)) ?>" data-bulk-select-all data-bulk-target="unassigned<?= ucfirst($deviceStatus) ?>BulkDelete"></th>
                                            <th>Device Type</th>
                                            <th>Brand</th>
                                            <th>Model</th>
                                            <th>Serial Number</th>
                                            <th>Firmware</th>
                                            <th>Assign User</th>
                                            <th>Actions</th>
                                        </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                <?php foreach ($statusDevices as $device): ?>
                                    <tr>
                                        <td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$device['id'] ?>" form="unassigned<?= ucfirst($deviceStatus) ?>BulkDelete" aria-label="Select device" data-bulk-select-item></td>
                                        <?php if ($deviceStatus === 'network'): ?>
                                            <td class="network-item-col" data-label="Item"><?= e($device['model'] ?: $device['device_name'] ?: 'Network Device') ?></td>
                                            <td class="network-ip-col" data-label="IP"><?= e($device['network_printer_address'] ?: '-') ?></td>
                                            <td class="network-serial-col" data-label="Serial Number"><?= e($device['serial_number'] ?: '-') ?></td>
                                            <td class="network-firmware-col" data-label="Firmware Version"><?= e($device['firmware_version'] ?: 'Awaiting reading') ?></td>
                                            <td class="network-cartridge-col" data-label="Cartridge Level"><?= e($cartridgeLevelText($device['network_printer_details'] ?? null)) ?></td>
                                            <td class="network-status-col text-center" data-label="Status"><?= $statusBadge($device) ?></td>
                                            <td data-label="Department"><?= e($device['assigned_employee_department'] ?: '-') ?></td>
                                            <td data-label="User"><?= e($device['assigned_employee_name'] ?: '-') ?></td>
                                        <?php elseif ($deviceStatus === 'online'): ?>
                                            <td data-label="Item"><?= e(trim(($device['manufacturer'] ?: '') . ' ' . ($device['model'] ?: '')) ?: '-') ?><?php if (!empty($device['network_printer_id'])): ?><br><span class="badge text-bg-info">Network Printer</span><br><small>IP: <?= e($device['network_printer_address']) ?><br>Firmware: <?= e($device['firmware_version'] ?: 'Awaiting reading') ?></small><?php endif; ?></td>
                                            <td data-label="Serial"><?= e($device['serial_number'] ?: '-') ?></td>
                                            <td data-label="MAC"><?= e($device['mac_address'] ?: '-') ?></td>
                                            <td data-label="RAM"><?= e($gbText($device['ram_bytes'] ?? null)) ?></td>
                                            <td data-label="Disk"><?= e($diskText($device['disk_summary'] ?? null)) ?></td>
                                            <td data-label="BIOS Version"><?= e($device['bios_version'] ?: '-') ?></td>
                                            <td class="device-cpu-col" data-label="CPU"><?= e($device['cpu_name'] ?: '-') ?></td>
                                            <td class="text-center device-battery-col" data-label="Battery Health"><?= e(($device['battery_health_percent'] ?? null) === null ? '-' : (int)$device['battery_health_percent'] . '%') ?></td>
                                            <td data-label="Operating System"><?= e($device['os_name'] ?: '-') ?></td>
                                            <td data-label="Device Name"><?= e($device['device_name'] ?: $device['computer_name'] ?: '-') ?></td>
                                            <td data-label="User">-</td>
                                            <td data-label="Status"><?= $statusBadge($device) ?></td>
                                        <?php else: ?>
                                            <td data-label="Device Type"><?= e($device['device_type'] ?: '-') ?></td>
                                            <td data-label="Brand"><?= e($device['manufacturer'] ?: '-') ?></td>
                                            <td data-label="Model"><?= e($device['model'] ?: '-') ?></td>
                                            <td data-label="Serial Number"><?= e($device['serial_number'] ?: '-') ?></td>
                                            <td data-label="Firmware"><?= e($device['firmware_version'] ?: '-') ?></td>
                                            <td data-label="Assign User">-</td>
                                        <?php endif; ?>
                                        <td class="text-end<?= $deviceStatus === 'network' ? ' network-actions-col' : '' ?>" data-label="Actions">
                                            <div class="table-actions unassigned-actions">
                                                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#assignDevice<?= (int)$device['id'] ?>">
                                                    <i class="bi bi-person-check"></i> Assign
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger unassigned-delete" type="button" title="Request deletion" aria-label="Request deletion of <?= e($device['device_name'] ?: $device['computer_name'] ?: 'device') ?>" data-delete-request data-entity-type="device" data-entity-id="<?= (int)$device['id'] ?>" data-entity-label="<?= e($device['device_name'] ?: $device['computer_name'] ?: 'Device') ?>"><i class="bi bi-trash"></i></button>
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

<div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-labelledby="deleteRequestModalLabel" aria-hidden="true" data-delete-request-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/delete-requests/store') ?>" data-delete-request-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="entity_type" data-delete-request-type>
                <input type="hidden" name="redirect_to" value="/unassigned-devices?status=<?= e($activeDeviceStatus) ?>">
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

<?php if ($employees && $devices): ?>
    <?php foreach ($devices as $device): ?>
        <div class="modal fade" id="assignDevice<?= (int)$device['id'] ?>" tabindex="-1" aria-labelledby="assignDeviceLabel<?= (int)$device['id'] ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="<?= $url('/unassigned-devices/assign') ?>" data-office-scoped-form>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="assignDeviceLabel<?= (int)$device['id'] ?>">Assign Device</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body compact-form">
                            <div>
                                <label class="form-label">Device Name</label>
                                <input class="form-control" value="<?= e(!empty($device['network_printer_id']) ? ($device['model'] ?: $device['device_name'] ?: $device['computer_name']) : ($device['device_name'] ?: $device['computer_name'])) ?>" disabled>
                            </div>
                            <div>
                                <label class="form-label">Office</label>
                                <select class="form-select assignment-native-select" name="office_id" data-office-source-select data-searchable-assignment-select data-search-placeholder="Search office" required>
                                    <option value="">Select office</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Employee</label>
                                <select class="form-select assignment-native-select" name="employee_id" data-office-scoped-select data-searchable-assignment-select data-search-placeholder="Search employee name or ID" required>
                                    <option value="">Select employee</option>
                                    <?php foreach ($employees as $employee):
                                        $employeeNumber = trim((string)($employee['employee_number'] ?? ''));
                                        $employeeLabel = $employee['name'] . ' — ' . ($employeeNumber !== '' ? $employeeNumber : 'No ID');
                                        $employeeSearch = trim($employee['name'] . ' ' . $employeeNumber);
                                    ?>
                                        <option value="<?= (int)$employee['id'] ?>" data-office-id="<?= (int)$employee['office_id'] ?>" data-search="<?= e($employeeSearch) ?>"><?= e($employeeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary"><i class="bi bi-check2"></i> Assign</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
