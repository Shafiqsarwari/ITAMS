<?php

class AssetController extends Controller
{
    public function index(): void
    {
        $user = $this->requirePermission('assets.view');
        $offlineAfter = (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes'];
        NetworkPrinterService::schema($this->db);
        $networkCollectors = NetworkPrinterService::collectors($this->db, $user);
        $deleteApprovers = DeleteRequestService::approvers('assets.delete');
        $onlineSql = NetworkPrinterService::onlineSql($offlineAfter);
        $openDeviceId = (int)Request::input('open_device');

        $officeWhere = '';
        $officeParams = [];
        if (!ScopeService::isAdmin($user)) {
            if (empty($user['office_id'])) {
                $this->view('assets/index', [
                    'title' => 'Device Management',
                    'offices' => [],
                    'devicesByOffice' => [],
                    'softwareByDevice' => [],
                    'driversByDevice' => [],
                    'softwareUpdatesByDevice' => [],
                    'driverUpdatesByDevice' => [],
                    'maintenanceByDevice' => [],
                    'employees' => [],
                    'offlineAfter' => $offlineAfter,
                    'openDeviceId' => 0,
                    'user' => $user,
                    'deleteApprovers' => $deleteApprovers,
                ]);
                return;
            }
            $officeIds = ScopeService::officeIdsForUser($user);
            if (!$officeIds) {
                $officeWhere = 'WHERE 1=0';
            } else {
                $officeWhere = 'WHERE offices.id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')';
                $officeParams = $officeIds;
            }
        }

        $stmt = $this->db->prepare(
            "SELECT offices.*,
                (SELECT COUNT(*) FROM computers WHERE computers.office_id = offices.id AND (computers.agent_token_hash IS NOT NULL OR EXISTS (SELECT 1 FROM network_printer_links np_count WHERE np_count.computer_id = computers.id))) AS total_devices,
                (SELECT COUNT(*) FROM computers WHERE computers.office_id = offices.id AND {$onlineSql}) AS online_devices
             FROM offices
             {$officeWhere}
             ORDER BY offices.name"
        );
        $stmt->execute($officeParams);
        $offices = $stmt->fetchAll();

        $devicesByOffice = [];
        $softwareByDevice = [];
        $driversByDevice = [];
        $softwareUpdatesByDevice = [];
        $driverUpdatesByDevice = [];
        $maintenanceByDevice = [];
        $visibleDeviceIds = [];
        $officeIds = array_map(static fn(array $office): int => (int)$office['id'], $offices);
        if ($officeIds) {
            $placeholders = implode(',', array_fill(0, count($officeIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT computers.*, offices.name AS office_name, users.name AS added_by_name,
                    employees.name AS assigned_employee_name,
                    employees.designation AS assigned_employee_designation,
                    employees.department AS assigned_employee_department,
                    bios_information.version AS bios_version,
                    CASE WHEN {$onlineSql} THEN 1 ELSE 0 END AS is_online,
                    np.computer_id AS network_printer_id, np.address AS network_printer_address, np.state AS network_printer_state,
                    nd.details AS network_printer_details,
                    (
                        SELECT network_information.mac_address
                        FROM network_information
                        WHERE network_information.computer_id = computers.id
                          AND network_information.mac_address IS NOT NULL
                          AND network_information.mac_address <> ''
                        ORDER BY CASE network_information.adapter_type WHEN 'LAN' THEN 0 WHEN 'WiFi' THEN 1 WHEN 'Other' THEN 2 WHEN 'Virtual' THEN 3 ELSE 4 END, network_information.id
                        LIMIT 1
                    ) AS primary_mac_address
                 FROM computers
                 LEFT JOIN offices ON offices.id = computers.office_id
                 LEFT JOIN users ON users.id = computers.added_by_user_id
                 LEFT JOIN employees ON employees.id = computers.assigned_employee_id
                 LEFT JOIN bios_information ON bios_information.computer_id = computers.id
                 LEFT JOIN network_printer_links np ON np.computer_id = computers.id
                 LEFT JOIN network_devices nd ON nd.collector_id = np.collector_id AND nd.address = np.address
                 WHERE computers.office_id IN ({$placeholders})
                   AND (computers.agent_token_hash IS NOT NULL OR np.computer_id IS NOT NULL)
                 ORDER BY computers.office_id, computers.last_checkin_at DESC, computers.device_name ASC, computers.computer_name ASC"
            );
            $stmt->execute($officeIds);
            $assets = $stmt->fetchAll();
            foreach ($assets as $asset) {
                $devicesByOffice[(int)$asset['office_id']][] = $asset;
            }

            $deviceIds = array_map(static fn(array $asset): int => (int)$asset['id'], $assets);
            $visibleDeviceIds = $deviceIds;
            if ($deviceIds) {
                $devicePlaceholders = implode(',', array_fill(0, count($deviceIds), '?'));
                $this->ensureInstalledDriversTable();
                $this->ensureDriverHistoryTable();
                $stmt = $this->db->prepare(
                    "SELECT * FROM installed_software
                     WHERE computer_id IN ({$devicePlaceholders})
                     ORDER BY name"
                );
                $stmt->execute($deviceIds);
                foreach ($stmt->fetchAll() as $item) {
                    if ($this->isVisibleSoftwareRow($item)) {
                        $softwareByDevice[(int)$item['computer_id']][] = $item;
                    }
                }

                $stmt = $this->db->prepare(
                    "SELECT * FROM installed_drivers
                     WHERE computer_id IN ({$devicePlaceholders})
                     ORDER BY device_name, version"
                );
                $stmt->execute($deviceIds);
                foreach ($stmt->fetchAll() as $item) {
                    $driversByDevice[(int)$item['computer_id']][] = $item;
                }

                $stmt = $this->db->prepare(
                    "SELECT id, computer_id, software_name, previous_version, current_version, created_at
                     FROM software_history
                     WHERE computer_id IN ({$devicePlaceholders})
                       AND change_type = 'updated'
                     ORDER BY created_at DESC, id DESC"
                );
                $stmt->execute($deviceIds);
                foreach ($stmt->fetchAll() as $item) {
                    if ($this->isVisibleSoftwareName($item['software_name'] ?? null)) {
                        $softwareUpdatesByDevice[(int)$item['computer_id']][] = $item;
                    }
                }

                $stmt = $this->db->prepare(
                    "SELECT id, computer_id, device_name, previous_version, current_version, created_at
                     FROM driver_history
                     WHERE computer_id IN ({$devicePlaceholders})
                       AND change_type = 'updated'
                     ORDER BY created_at DESC, id DESC"
                );
                $stmt->execute($deviceIds);
                foreach ($stmt->fetchAll() as $item) {
                    $driverUpdatesByDevice[(int)$item['computer_id']][] = $item;
                }

                $this->ensureDeviceMaintenanceHistoryTable();
                $this->ensureDeviceMaintenanceHiddenRecordsTable();
                $stmt = $this->db->prepare(
                    "SELECT device_maintenance_history.*
                     FROM device_maintenance_history
                     WHERE computer_id IN ({$devicePlaceholders})
                       AND NOT EXISTS (
                           SELECT 1
                           FROM device_maintenance_hidden_records
                           WHERE device_maintenance_hidden_records.maintenance_id = device_maintenance_history.id
                             AND device_maintenance_hidden_records.user_id = ?
                       )
                     ORDER BY changed_at DESC, id DESC"
                );
                $stmt->execute([...$deviceIds, (int)$user['id']]);
                foreach ($stmt->fetchAll() as $item) {
                    $maintenanceByDevice[(int)$item['computer_id']][] = $item;
                }
            }
        }

        if ($openDeviceId <= 0 || !in_array($openDeviceId, $visibleDeviceIds, true)) {
            $openDeviceId = 0;
        }

        $employees = $this->employeeOptions($user);
        $this->view('assets/index', compact('offices', 'devicesByOffice', 'softwareByDevice', 'driversByDevice', 'softwareUpdatesByDevice', 'driverUpdatesByDevice', 'maintenanceByDevice', 'employees', 'offlineAfter', 'openDeviceId', 'user', 'networkCollectors', 'deleteApprovers') + ['title' => 'Device Management']);
    }

    public function unassigned(): void
    {
        $user = $this->requirePermission('offices.manage');
        $offlineAfter = (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes'];
        $activeDeviceStatus = Request::input('status') === 'network' ? 'network' : 'online';
        NetworkPrinterService::schema($this->db);
        $onlineSql = NetworkPrinterService::onlineSql($offlineAfter);
        [$collectorScope, $collectorParams] = ScopeService::assetWhere($user, 'cc');

        $stmt = $this->db->prepare(
            "SELECT computers.*,
                employees.name AS assigned_employee_name,
                employees.designation AS assigned_employee_designation,
                employees.department AS assigned_employee_department,
                bios_information.version AS bios_version,
                CASE WHEN {$onlineSql} THEN 1 ELSE 0 END AS is_online,
                np.computer_id AS network_printer_id, np.address AS network_printer_address, np.state AS network_printer_state,
                nd.details AS network_printer_details
             FROM computers
             LEFT JOIN employees ON employees.id = computers.assigned_employee_id
             LEFT JOIN bios_information ON bios_information.computer_id = computers.id
             LEFT JOIN network_printer_links np ON np.computer_id = computers.id
             LEFT JOIN network_devices nd ON nd.collector_id = np.collector_id AND nd.address = np.address
             WHERE computers.office_id IS NULL
               AND (computers.agent_token_hash IS NOT NULL OR np.computer_id IS NOT NULL)
               AND (np.computer_id IS NULL OR np.collector_id IN (SELECT cc.id FROM computers cc WHERE {$collectorScope}))
             ORDER BY computers.last_checkin_at DESC, computers.created_at DESC, computers.device_name ASC, computers.computer_name ASC"
        );
        $stmt->execute($collectorParams);
        $devices = $stmt->fetchAll();
        [$employeeWhere, $employeeParams] = ScopeService::isAdmin($user)
            ? ['employees.office_id IS NOT NULL', []]
            : $this->officeColumnScope($user, 'employees.office_id');
        $stmt = $this->db->prepare(
            'SELECT employees.id, employees.name, employees.employee_number, employees.designation, employees.department,
                employees.office_id,
                offices.name AS office_name,
                COUNT(computers.id) AS assigned_assets_count
             FROM employees
             LEFT JOIN offices ON offices.id = employees.office_id
             LEFT JOIN computers ON computers.assigned_employee_id = employees.id
             WHERE ' . $employeeWhere . '
             GROUP BY employees.id, employees.name, employees.employee_number, employees.designation, employees.department, employees.office_id, offices.name
             ORDER BY employees.name'
        );
        $stmt->execute($employeeParams);
        $employees = $stmt->fetchAll();

        [$officeWhere, $officeParams] = $this->officeColumnScope($user, 'offices.id');
        $stmt = $this->db->prepare('SELECT offices.id, offices.name FROM offices WHERE ' . $officeWhere . ' ORDER BY offices.name');
        $stmt->execute($officeParams);
        $offices = $stmt->fetchAll();
        $deleteApprovers = DeleteRequestService::approvers('assets.delete');

        $this->view('assets/unassigned', compact('devices', 'employees', 'offices', 'offlineAfter', 'activeDeviceStatus', 'deleteApprovers') + ['title' => 'Unassigned Device']);
    }

    public function assignOffice(): void
    {
        $user = $this->requirePermission('offices.manage');
        Csrf::verify();

        $deviceId = (int)Request::input('device_id');
        $officeId = (int)Request::input('office_id');
        $employeeId = (int)Request::input('employee_id');

        if ($deviceId <= 0 || $officeId <= 0 || $employeeId <= 0) {
            $this->flash('danger', 'Select a device, office, and employee before assigning.');
            Response::redirect('/unassigned-devices');
        }

        $device = $this->one('SELECT * FROM computers WHERE id = ? AND office_id IS NULL', [$deviceId]);
        NetworkPrinterService::schema($this->db);
        if ($device && !NetworkPrinterService::canAccessUnassigned($this->db, $deviceId, $user)) {
            http_response_code(403);
            return;
        }
        if (!$device) {
            $this->flash('danger', 'Device is no longer unassigned.');
            Response::redirect('/unassigned-devices');
        }

        $office = $this->one('SELECT id, name FROM offices WHERE id = ?', [$officeId]);
        if (!$office || !ScopeService::canManageOffice($user, $officeId)) {
            $this->flash('danger', 'Selected office was not found or is outside your access.');
            Response::redirect('/unassigned-devices');
        }

        $employee = $this->one(
            'SELECT employees.*, offices.name AS office_name
             FROM employees
             LEFT JOIN offices ON offices.id = employees.office_id
             WHERE employees.id = ?',
            [$employeeId]
        );
        if (!$employee) {
            $this->flash('danger', 'Selected employee was not found.');
            Response::redirect('/unassigned-devices');
        }

        if (empty($employee['office_id']) || (int)$employee['office_id'] !== $officeId) {
            $this->flash('danger', 'Selected employee must belong to the selected office.');
            Response::redirect('/unassigned-devices');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE computers SET office_id = ?, assigned_employee_id = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$officeId, $employeeId, $deviceId]);

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        Audit::log('assigned_office', 'device', $deviceId, [
            'office_id' => $officeId,
            'office' => $office['name'],
            'employee_id' => $employeeId,
            'employee' => $employee['name'],
            'device' => $device['device_name'] ?: $device['computer_name'],
        ]);
        $this->flash('success', 'Device assigned to ' . $employee['name'] . ' in ' . $office['name'] . '.');
        Response::redirect('/unassigned-devices');
    }

    public function unassignOffice(): void
    {
        $user = $this->requirePermission('offices.manage');
        Csrf::verify();

        $deviceId = (int)Request::input('device_id');
        if ($deviceId <= 0) {
            $this->flash('danger', 'Select a device before unassigning.');
            Response::redirect('/device');
        }

        $device = $this->one(
            'SELECT computers.*, offices.name AS office_name
             FROM computers
             LEFT JOIN offices ON offices.id = computers.office_id
             WHERE computers.id = ? AND computers.office_id IS NOT NULL',
            [$deviceId]
        );
        if (!$device) {
            $this->flash('danger', 'Device is already unassigned or was not found.');
            Response::redirect('/device');
        }
        if (!$this->findScopedAsset($deviceId, $user)) {
            $this->flash('danger', 'You can only remove devices from your own office.');
            Response::redirect('/device');
        }

        $stmt = $this->db->prepare('UPDATE computers SET office_id = NULL, assigned_employee_id = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$deviceId]);

        Audit::log('unassigned_office', 'device', $deviceId, [
            'office_id' => $device['office_id'],
            'office' => $device['office_name'],
            'device' => $device['device_name'] ?: $device['computer_name'],
        ]);
        $this->flash('success', 'Device moved to Unassigned.');
        $offlineAfter = (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes'];
        $isOnline = empty($device['agent_uninstalled_at'])
            && !empty($device['last_checkin_at'])
            && strtotime((string)$device['last_checkin_at']) >= strtotime("-{$offlineAfter} minutes");
        $targetStatus = $isOnline ? 'online' : 'offline';
        Response::redirect('/unassigned-devices?status=' . $targetStatus);
    }

    public function changeAssignment(): void
    {
        $user = $this->requirePermission('offices.manage');
        Csrf::verify();

        $deviceId = (int)Request::input('device_id');
        $officeId = (int)Request::input('office_id');
        $employeeId = (int)Request::input('assigned_employee_id', 0);

        $asset = $this->findScopedAsset($deviceId, $user);
        if (!$asset || $officeId <= 0) {
            $this->flash('danger', 'Select a valid device and office.');
            Response::redirect('/device');
        }

        $office = $this->one('SELECT id, name FROM offices WHERE id = ?', [$officeId]);
        if (!$office || !ScopeService::canManageOffice($user, $officeId)) {
            $this->flash('danger', 'Selected office was not found.');
            Response::redirect('/device');
        }

        $assignedEmployeeId = null;
        $employeeName = null;
        if ($employeeId > 0) {
            $employee = $this->one('SELECT id, name, office_id FROM employees WHERE id = ?', [$employeeId]);
            if (!$employee || (int)$employee['office_id'] !== $officeId) {
                $this->flash('danger', 'Selected user must belong to the selected office.');
                Response::redirect('/device');
            }
            $assignedEmployeeId = $employeeId;
            $employeeName = $employee['name'];
        }

        $stmt = $this->db->prepare('UPDATE computers SET office_id = ?, assigned_employee_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$officeId, $assignedEmployeeId, $deviceId]);

        Audit::log('changed_assignment', 'device', $deviceId, [
            'office_id' => $officeId,
            'office' => $office['name'],
            'employee_id' => $assignedEmployeeId,
            'employee' => $employeeName,
            'device' => $asset['device_name'] ?: $asset['computer_name'],
        ]);
        $this->flash('success', 'Device assignment updated.');
        Response::redirect('/device');
    }

    public function create(): void
    {
        $user = $this->requirePermission('assets.manage');
        $offices = $this->officeOptions($user);
        $employees = $this->employeeOptions($user);
        $this->view('assets/form', compact('user', 'offices', 'employees') + ['title' => 'Add Device', 'assetRecord' => null]);
    }

    public function store(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();

        $data = $this->validatedFormData($user, '/device/create');

        $computerName = $this->uniqueComputerName($data['device_name']);
        $stmt = $this->db->prepare(
            'INSERT INTO computers
                (computer_name, device_name, device_type, manufacturer, serial_number, model, firmware_version, is_offline_device, office_id, assigned_employee_id, added_by_user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $computerName,
            $data['device_name'],
            $data['device_type'],
            $data['manufacturer'],
            $data['serial_number'],
            $data['model'],
            $data['firmware_version'],
            $data['is_offline_device'],
            $data['office_id'],
            $data['assigned_employee_id'],
            $user['id'],
        ]);
        Audit::log('created', 'device', (int)$this->db->lastInsertId(), ['office_id' => $data['office_id']]);
        $this->flash('success', 'Device added.');
        Response::redirect('/device');
    }

    public function edit(): void
    {
        $user = $this->requirePermission('assets.manage');
        $assetRecord = $this->findScopedAsset((int)Request::input('id'), $user);
        if (!$assetRecord) {
            http_response_code(404);
            echo 'Device not found';
            return;
        }

        $offices = $this->officeOptions($user);
        $employees = $this->employeeOptions($user);
        $this->view('assets/form', compact('user', 'offices', 'employees', 'assetRecord') + ['title' => 'Edit Device']);
    }

    public function update(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        $id = (int)Request::input('id');
        $asset = $this->findScopedAsset($id, $user);
        if (!$asset) {
            http_response_code(404);
            echo 'Device not found';
            return;
        }

        $data = $this->validatedFormData($user, '/device/edit?id=' . $id);
        $stmt = $this->db->prepare(
            'UPDATE computers
             SET device_name = ?, device_type = ?, manufacturer = ?, serial_number = ?, model = ?, firmware_version = ?,
                 is_offline_device = ?, office_id = ?, assigned_employee_id = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $data['device_name'],
            $data['device_type'],
            $data['manufacturer'],
            $data['serial_number'],
            $data['model'],
            $data['firmware_version'],
            $data['is_offline_device'],
            $data['office_id'],
            $data['assigned_employee_id'],
            $id,
        ]);
        $this->recordOfflineFirmwareChange($id, $asset, $data);
        $this->recordOfflineDeviceNameChange($id, $asset, $data);
        Audit::log('updated', 'device', $id, ['office_id' => $data['office_id']]);
        $this->flash('success', 'Device updated.');
        Response::redirect('/device');
    }

    public function delete(): void
    {
        $user = $this->requirePermission('assets.delete');
        Csrf::verify();
        $this->flash('info', 'Device deletion requires approval. Submit a delete request from Device Management.');
        Response::redirect('/device');
        $ids = $this->selectedIds();
        if (!$ids) {
            http_response_code(404);
            echo 'Device not found';
            return;
        }

        $assets = [];
        foreach ($ids as $id) {
            $asset = $this->findScopedAsset($id, $user);
            if ($asset) {
                $assets[] = $asset;
            }
        }
        if (!$assets) {
            http_response_code(404);
            echo 'Device not found';
            return;
        }

        $deleteIds = array_map(static fn(array $asset): int => (int)$asset['id'], $assets);
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM computers WHERE id IN ({$placeholders})");
        $stmt->execute($deleteIds);
        foreach ($assets as $asset) {
            Audit::log('deleted', 'device', (int)$asset['id'], ['device' => $asset['device_name'] ?: $asset['computer_name']]);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'Device deleted.' : count($deleteIds) . ' devices deleted.');
        $redirectTo = (string)Request::input('redirect_to', '/device');
        $allowedRedirects = ['/device', '/unassigned-devices', '/unassigned-devices?status=online', '/unassigned-devices?status=offline'];
        Response::redirect(in_array($redirectTo, $allowedRedirects, true) ? $redirectTo : '/device');
    }

    public function storeUpdateAssignment(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        if (!$this->canManageAssignments($user)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $officeId = (int)Request::input('office_id');
        $deviceId = (int)Request::input('device_id');
        $assignedToUserId = (int)Request::input('assigned_to_user_id');
        $taskTypes = Request::input('task_types', []);
        $taskTypes = is_array($taskTypes) ? array_values(array_unique(array_map('strval', $taskTypes))) : [];
        $taskTypes = array_values(array_intersect($taskTypes, array_keys($this->updateAssignmentTaskMap())));
        $note = trim((string)Request::input('note'));
        if (!$taskTypes || $officeId <= 0 || $deviceId <= 0 || $assignedToUserId <= 0) {
            $this->flash('danger', 'Select a task type, office, device, and user before assigning.');
            Response::redirect('/task');
        }

        $asset = $this->findScopedAsset($deviceId, $user);
        $assignee = $this->one(
            'SELECT users.id, users.name, users.office_id, roles.slug AS role_slug
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.status = \'active\' AND roles.slug IN (\'admin\', \'user\')',
            [$assignedToUserId]
        );
        if (!$asset || !$assignee || (int)($asset['office_id'] ?? 0) !== $officeId || !ScopeService::canManageOffice($user, $officeId) || !$this->assigneeCanReceiveOffice($assignee, $officeId)) {
            $this->flash('danger', 'The selected device or user is no longer available.');
            Response::redirect('/task');
        }

        $this->ensureUpdateAssignmentsTable();
        $softwareName = trim((string)Request::input('software_name'));
        if (in_array('software_update', $taskTypes, true)) {
            $software = $this->one('SELECT id FROM installed_software WHERE computer_id = ? AND name = ?', [$deviceId, $softwareName]);
            if (!$software) {
                $this->flash('danger', 'Select software installed on the selected device.');
                Response::redirect('/task');
            }
        }
        $groupKey = bin2hex(random_bytes(12));
        $stmt = $this->db->prepare(
            'INSERT INTO update_assignments
                (assignment_group_key, computer_id, task_kind, task_type, software_name, assigned_to_user_id, assigned_by_user_id, note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", NOW(), NOW())'
        );
        foreach ($taskTypes as $taskType) {
            $stmt->execute([
                $groupKey,
                $deviceId,
                $taskType === 'software_update' ? 'software' : 'device',
                $taskType,
                $taskType === 'software_update' && $softwareName !== '' ? $softwareName : null,
                $assignedToUserId,
                (int)$user['id'],
                $note === '' ? null : $note,
            ]);
        }

        Audit::log('assigned_update', 'device', $deviceId, [
            'assignment_group_key' => $groupKey,
            'task_types' => $taskTypes,
            'assigned_to_user_id' => $assignedToUserId,
            'assigned_to_user' => $assignee['name'],
        ]);
        $this->flash('success', count($taskTypes) . ' update task' . (count($taskTypes) === 1 ? '' : 's') . ' assigned to ' . $assignee['name'] . '.');
        Response::redirect('/task');
    }

    public function deleteMaintenance(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        $ids = $this->selectedIds();
        if (!$ids) {
            if (Request::expectsJson()) {
                Response::json(['ok' => false, 'error' => 'Maintenance record not found'], 404);
            }
            http_response_code(404);
            echo 'Maintenance record not found';
            return;
        }

        $this->ensureDeviceMaintenanceHistoryTable();
        $this->ensureDeviceMaintenanceHiddenRecordsTable();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT device_maintenance_history.*, computers.device_name, computers.computer_name
             FROM device_maintenance_history
             INNER JOIN computers ON computers.id = device_maintenance_history.computer_id
             WHERE device_maintenance_history.id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);
        $records = array_values(array_filter($stmt->fetchAll(), fn(array $record): bool => (bool)$this->findScopedAsset((int)$record['computer_id'], $user)));
        if (!$records) {
            if (Request::expectsJson()) {
                Response::json(['ok' => false, 'error' => 'Maintenance record not found'], 404);
            }
            http_response_code(404);
            echo 'Maintenance record not found';
            return;
        }

        $deleteIds = array_map(static fn(array $record): int => (int)$record['id'], $records);
        $hide = $this->db->prepare(
            'INSERT IGNORE INTO device_maintenance_hidden_records (maintenance_id, user_id, created_at)
             VALUES (?, ?, NOW())'
        );
        foreach ($deleteIds as $deleteId) {
            $hide->execute([$deleteId, (int)$user['id']]);
        }
        foreach ($records as $record) {
            Audit::log('hid_maintenance_record', 'device', (int)$record['computer_id'], [
                'device' => $record['device_name'] ?: $record['computer_name'],
                'field' => $record['display_name'] ?: $record['field_name'],
                'maintenance_id' => (int)$record['id'],
            ]);
        }
        if (Request::expectsJson()) {
            Response::json(['ok' => true, 'ids' => $deleteIds, 'id' => $deleteIds[0] ?? 0, 'deleted' => count($deleteIds)]);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'Maintenance record hidden for your account.' : count($deleteIds) . ' maintenance records hidden for your account.');
        Response::redirect('/device');
    }

    public function deleteSoftwareUpdate(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        $ids = $this->selectedIds();
        if (!$ids) {
            if (Request::expectsJson()) {
                Response::json(['ok' => false, 'error' => 'Software update record not found'], 404);
            }
            http_response_code(404);
            echo 'Software update record not found';
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT software_history.*, computers.device_name, computers.computer_name
             FROM software_history
             INNER JOIN computers ON computers.id = software_history.computer_id
             WHERE software_history.id IN ({$placeholders})
               AND software_history.change_type = 'updated'"
        );
        $stmt->execute($ids);
        $records = array_values(array_filter($stmt->fetchAll(), fn(array $record): bool => (bool)$this->findScopedAsset((int)$record['computer_id'], $user)));
        if (!$records) {
            if (Request::expectsJson()) {
                Response::json(['ok' => false, 'error' => 'Software update record not found'], 404);
            }
            http_response_code(404);
            echo 'Software update record not found';
            return;
        }

        $deleteIds = array_map(static fn(array $record): int => (int)$record['id'], $records);
        $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $delete = $this->db->prepare("DELETE FROM software_history WHERE id IN ({$deletePlaceholders})");
        $delete->execute($deleteIds);
        foreach ($records as $record) {
            Audit::log('deleted_software_update_record', 'device', (int)$record['computer_id'], [
                'device' => $record['device_name'] ?: $record['computer_name'],
                'software' => $record['software_name'],
                'previous_version' => $record['previous_version'],
                'current_version' => $record['current_version'],
            ]);
        }
        if (Request::expectsJson()) {
            Response::json([
                'ok' => true,
                'ids' => $deleteIds,
                'id' => $deleteIds[0] ?? 0,
                'deviceId' => (int)$records[0]['computer_id'],
                'deleted' => count($deleteIds),
            ]);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'Software update record deleted.' : count($deleteIds) . ' software update records deleted.');
        Response::redirect('/device');
    }

    public function deleteDriverUpdate(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        $ids = $this->selectedIds();
        if (!$ids) {
            if (Request::expectsJson()) {
                Response::json(['ok' => false, 'error' => 'Driver update record not found'], 404);
            }
            http_response_code(404);
            echo 'Driver update record not found';
            return;
        }

        $this->ensureDriverHistoryTable();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT driver_history.*, computers.device_name AS computer_device_name, computers.computer_name
             FROM driver_history
             INNER JOIN computers ON computers.id = driver_history.computer_id
             WHERE driver_history.id IN ({$placeholders})
               AND driver_history.change_type = 'updated'"
        );
        $stmt->execute($ids);
        $records = array_values(array_filter($stmt->fetchAll(), fn(array $record): bool => (bool)$this->findScopedAsset((int)$record['computer_id'], $user)));
        if (!$records) {
            if (Request::expectsJson()) {
                Response::json(['ok' => false, 'error' => 'Driver update record not found'], 404);
            }
            http_response_code(404);
            echo 'Driver update record not found';
            return;
        }

        $deleteIds = array_map(static fn(array $record): int => (int)$record['id'], $records);
        $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $delete = $this->db->prepare("DELETE FROM driver_history WHERE id IN ({$deletePlaceholders})");
        $delete->execute($deleteIds);
        foreach ($records as $record) {
            Audit::log('deleted_driver_update_record', 'device', (int)$record['computer_id'], [
                'device' => $record['computer_device_name'] ?: $record['computer_name'],
                'driver' => $record['device_name'],
                'previous_version' => $record['previous_version'],
                'current_version' => $record['current_version'],
            ]);
        }
        if (Request::expectsJson()) {
            Response::json([
                'ok' => true,
                'ids' => $deleteIds,
                'id' => $deleteIds[0] ?? 0,
                'deviceId' => (int)$records[0]['computer_id'],
                'deleted' => count($deleteIds),
            ]);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'Driver update record deleted.' : count($deleteIds) . ' driver update records deleted.');
        Response::redirect('/device');
    }

    public function requestSoftwareSync(): void
    {
        $user = $this->requirePermission('assets.view');
        Csrf::verify();

        $id = (int)Request::input('device_id');
        $asset = $this->findScopedAsset($id, $user);
        if (!$asset) {
            http_response_code(404);
            echo 'Device not found';
            return;
        }

        $this->ensureSoftwareSyncRequestsTable();
        $pending = $this->db->prepare('SELECT id FROM software_sync_requests WHERE computer_id = ? AND completed_at IS NULL LIMIT 1');
        $pending->execute([$id]);
        $syncId = (int)$pending->fetchColumn();
        if (!$syncId) {
            $stmt = $this->db->prepare(
                'INSERT INTO software_sync_requests (computer_id, requested_by_user_id, requested_at, created_at, updated_at)
                 VALUES (?, ?, NOW(), NOW(), NOW())'
            );
            $stmt->execute([$id, $user['id'] ?? null]);
            $syncId = (int)$this->db->lastInsertId();
        }

        Audit::log('requested_software_sync', 'device', $id, [
            'device' => $asset['device_name'] ?: $asset['computer_name'],
        ]);
        if (Request::expectsJson()) {
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            Response::json([
                'ok' => true,
                'syncId' => $syncId,
                'statusUrl' => ($basePath ?: '') . '/device/software-sync-status?request_id=' . $syncId . '&device_id=' . $id,
                'message' => 'Software sync started. Waiting for the endpoint to upload the latest software list.',
            ]);
        }

        $this->flash('success', 'Software sync requested. The endpoint will upload the latest software list on its next heartbeat.');
        Response::redirect('/device');
    }

    public function softwareSyncStatus(): void
    {
        $user = $this->requirePermission('assets.view');
        $deviceId = (int)Request::input('device_id');
        $requestId = (int)Request::input('request_id');
        $asset = $this->findScopedAsset($deviceId, $user);
        if (!$asset || $requestId <= 0) {
            Response::json(['ok' => false, 'error' => 'Sync request was not found.'], 404);
        }

        $this->ensureSoftwareSyncRequestsTable();
        $stmt = $this->db->prepare(
            'SELECT *
             FROM software_sync_requests
             WHERE id = ? AND computer_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId, $deviceId]);
        $request = $stmt->fetch();
        if (!$request) {
            Response::json(['ok' => false, 'error' => 'Sync request was not found.'], 404);
        }

        if (empty($request['completed_at'])) {
            Response::json([
                'ok' => true,
                'status' => 'pending',
                'message' => 'Waiting for the endpoint agent to upload the latest software list.',
            ]);
        }

        $software = $this->softwareRows($deviceId);
        $drivers = $this->driverRows($deviceId);
        $softwareUpdates = $this->softwareUpdateRows($deviceId);
        $driverUpdates = $this->driverUpdateRows($deviceId);
        Response::json([
            'ok' => true,
            'status' => 'completed',
            'message' => 'Software sync completed.',
            'completedAt' => $request['completed_at'],
            'softwareCount' => count($software),
            'software' => $software,
            'driverCount' => count($drivers),
            'drivers' => $drivers,
            'softwareUpdateCount' => count($softwareUpdates),
            'softwareUpdates' => $softwareUpdates,
            'driverUpdateCount' => count($driverUpdates),
            'driverUpdates' => $driverUpdates,
        ]);
    }

    public function requestDriverSync(): void
    {
        $user = $this->requirePermission('assets.view');
        Csrf::verify();

        $id = (int)Request::input('device_id');
        $asset = $this->findScopedAsset($id, $user);
        if (!$asset) {
            http_response_code(404);
            echo 'Device not found';
            return;
        }

        $this->ensureDriverSyncRequestsTable();
        $pending = $this->db->prepare('SELECT id FROM driver_sync_requests WHERE computer_id = ? AND completed_at IS NULL LIMIT 1');
        $pending->execute([$id]);
        $syncId = (int)$pending->fetchColumn();
        if (!$syncId) {
            $stmt = $this->db->prepare(
                'INSERT INTO driver_sync_requests (computer_id, requested_by_user_id, requested_at, created_at, updated_at)
                 VALUES (?, ?, NOW(), NOW(), NOW())'
            );
            $stmt->execute([$id, $user['id'] ?? null]);
            $syncId = (int)$this->db->lastInsertId();
        }

        Audit::log('requested_driver_sync', 'device', $id, [
            'device' => $asset['device_name'] ?: $asset['computer_name'],
        ]);
        if (Request::expectsJson()) {
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            Response::json([
                'ok' => true,
                'syncId' => $syncId,
                'statusUrl' => ($basePath ?: '') . '/device/driver-sync-status?request_id=' . $syncId . '&device_id=' . $id,
                'message' => 'Driver sync started. Waiting for the endpoint to upload the latest driver list.',
            ]);
        }

        $this->flash('success', 'Driver sync requested. The endpoint will upload the latest driver list on its next heartbeat.');
        Response::redirect('/device');
    }

    public function driverSyncStatus(): void
    {
        $user = $this->requirePermission('assets.view');
        $deviceId = (int)Request::input('device_id');
        $requestId = (int)Request::input('request_id');
        $asset = $this->findScopedAsset($deviceId, $user);
        if (!$asset || $requestId <= 0) {
            Response::json(['ok' => false, 'error' => 'Sync request was not found.'], 404);
        }

        $this->ensureDriverSyncRequestsTable();
        $stmt = $this->db->prepare(
            'SELECT *
             FROM driver_sync_requests
             WHERE id = ? AND computer_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId, $deviceId]);
        $request = $stmt->fetch();
        if (!$request) {
            Response::json(['ok' => false, 'error' => 'Sync request was not found.'], 404);
        }

        if (empty($request['completed_at'])) {
            Response::json([
                'ok' => true,
                'status' => 'pending',
                'message' => 'Waiting for the endpoint agent to upload the latest driver list.',
            ]);
        }

        $drivers = $this->driverRows($deviceId);
        $driverUpdates = $this->driverUpdateRows($deviceId);
        Response::json([
            'ok' => true,
            'status' => 'completed',
            'message' => 'Driver sync completed.',
            'completedAt' => $request['completed_at'],
            'driverCount' => count($drivers),
            'drivers' => $drivers,
            'driverUpdateCount' => count($driverUpdates),
            'driverUpdates' => $driverUpdates,
        ]);
    }

    public function export(): void
    {
        $user = $this->requirePermission('reports.export');
        $offlineAfter = (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes'];
        [$scopeWhere, $scopeParams] = ScopeService::assetWhere($user);
        $stmt = $this->db->prepare(
            "SELECT computers.device_name, computers.device_type, computers.mac_address, computers.serial_number, computers.model, computers.os_name,
                computers.ram_bytes, computers.disk_summary,
                employees.name AS assigned_employee_name,
                employees.designation AS assigned_employee_designation,
                employees.department AS assigned_employee_department,
                CASE
                    WHEN computers.agent_uninstalled_at IS NULL
                     AND computers.last_checkin_at >= NOW() - INTERVAL {$offlineAfter} MINUTE
                    THEN 'Online' ELSE 'Offline'
                END AS connection_status,
                computers.last_checkin_at, computers.agent_version
             FROM computers
             LEFT JOIN employees ON employees.id = computers.assigned_employee_id
             WHERE {$scopeWhere}
             ORDER BY computers.device_name"
        );
        $stmt->execute($scopeParams);
        $headers = ['Device', 'Type', 'MAC Address', 'Serial', 'Model', 'OS', 'RAM', 'Hard Drive', 'Assigned User', 'Designation', 'Department', 'Status', 'Last Check-in', 'Agent Version'];
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                $row['device_name'],
                $row['device_type'],
                $row['mac_address'],
                $row['serial_number'],
                $row['model'],
                $row['os_name'],
                $this->formatGb($row['ram_bytes'] ?? null),
                $this->formatDiskSummary($row['disk_summary'] ?? null),
                $row['assigned_employee_name'],
                $row['assigned_employee_designation'],
                $row['assigned_employee_department'],
                $row['connection_status'],
                AppDate::dateTime($row['last_checkin_at']),
                $row['agent_version'],
            ];
        }
        $format = strtolower((string)Request::input('format', 'csv'));
        if ($format === 'xlsx' || $format === 'xls') {
            Response::downloadExcel('device_inventory.xls', $headers, $rows);
        }
        if ($format === 'pdf') {
            Response::downloadPdf('device_inventory.pdf', 'Device Inventory Report', $headers, $rows);
        }
        Response::downloadCsv('device_inventory.csv', $headers, $rows);
    }

    private function formatGb($bytes): string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes) || (float)$bytes <= 0) {
            return '-';
        }

        $gb = (float)$bytes / 1073741824;
        $value = $gb >= 10 ? round($gb) : round($gb, 1);
        return number_format($value, $value == floor($value) ? 0 : 1) . ' GB';
    }

    private function formatDiskSummary(?string $diskSummary): string
    {
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

        return $this->formatGb($totalBytes);
    }

    private function softwareRows(int $deviceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, version, publisher, installed_date, last_updated_date
             FROM installed_software
             WHERE computer_id = ?
             ORDER BY name'
        );
        $stmt->execute([$deviceId]);
        return array_values(array_filter($stmt->fetchAll(), fn(array $item): bool => $this->isVisibleSoftwareRow($item)));
    }

    private function softwareUpdateRows(int $deviceId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, software_name, previous_version, current_version, created_at
             FROM software_history
             WHERE computer_id = ?
               AND change_type = 'updated'
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$deviceId]);
        return array_values(array_filter($stmt->fetchAll(), fn(array $item): bool => $this->isVisibleSoftwareName($item['software_name'] ?? null)));
    }

    private function driverRows(int $deviceId): array
    {
        $this->ensureInstalledDriversTable();
        $stmt = $this->db->prepare(
            'SELECT device_name, version, provider, manufacturer, installed_date, last_updated_date, device_class
             FROM installed_drivers
             WHERE computer_id = ?
             ORDER BY device_name, version'
        );
        $stmt->execute([$deviceId]);
        return $stmt->fetchAll();
    }

    private function driverUpdateRows(int $deviceId): array
    {
        $this->ensureDriverHistoryTable();
        $stmt = $this->db->prepare(
            "SELECT id, device_name, previous_version, current_version, created_at
             FROM driver_history
             WHERE computer_id = ?
               AND change_type = 'updated'
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$deviceId]);
        return $stmt->fetchAll();
    }

    private function isVisibleSoftwareRow(array $item): bool
    {
        return $this->isVisibleSoftwareName($item['name'] ?? null);
    }

    private function isVisibleSoftwareName(mixed $value): bool
    {
        $name = trim((string)$value);
        if ($name === '' || preg_match('/^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$/i', $name)) {
            return false;
        }

        return !preg_match('/^(Microsoft(?:\.Windows| Windows[A-Z.])|MicrosoftWindows[ .]|Windows[ .]|WindowsApps[ .]|WindowsSubsystem[ .]|cr[ .]sb[ .])/i', $name);
    }

    private function uniqueComputerName(string $deviceName): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $deviceName) ?: 'DEVICE';
        $name = substr(trim($base, '-'), 0, 120) ?: 'DEVICE';
        $candidate = $name;
        $i = 1;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM computers WHERE computer_name = ?');
        while (true) {
            $stmt->execute([$candidate]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $candidate = substr($name, 0, 110) . '-' . (++$i);
        }
    }

    private function validatedFormData(array $user, string $redirectPath): array
    {
        $officeId = (int)Request::input('office_id');
        if ($officeId <= 0) {
            $this->flash('danger', 'An office is required before saving a device.');
            Response::redirect($redirectPath);
        }

        if (!ScopeService::canManageOffice($user, $officeId)) {
            $this->flash('danger', 'Selected office is outside your access.');
            Response::redirect($redirectPath);
        }

        $manufacturer = trim((string)Request::input('manufacturer'));
        $model = trim((string)Request::input('model'));
        $serialNumber = trim((string)Request::input('serial_number'));

        if ($manufacturer === '' || $model === '' || $serialNumber === '') {
            $this->flash('danger', 'Brand, model, and serial number are required.');
            Response::redirect($redirectPath);
        }

        return [
            'office_id' => $officeId,
            'device_name' => trim($manufacturer . ' ' . $model . ' ' . $serialNumber),
            'device_type' => trim((string)Request::input('device_type', 'Computer')) ?: 'Device',
            'manufacturer' => $manufacturer,
            'serial_number' => $serialNumber,
            'model' => $model,
            'firmware_version' => trim((string)Request::input('firmware_version')) ?: null,
            'assigned_employee_id' => $this->validatedEmployeeId($officeId, $redirectPath),
            'is_offline_device' => 1,
        ];
    }

    private function validatedEmployeeId(int $officeId, string $redirectPath): ?int
    {
        $employeeId = (int)Request::input('assigned_employee_id', 0);
        if ($employeeId <= 0) {
            return null;
        }

        $employee = $this->one('SELECT id, office_id FROM employees WHERE id = ?', [$employeeId]);
        if (!$employee || (int)$employee['office_id'] !== $officeId) {
            $this->flash('danger', 'Selected employee must belong to the selected office.');
            Response::redirect($redirectPath);
        }

        return $employeeId;
    }

    private function recordOfflineFirmwareChange(int $deviceId, array $asset, array $data): void
    {
        if (!empty($asset['agent_token_hash']) && empty($asset['agent_uninstalled_at'])) {
            return;
        }

        $previousFirmware = trim((string)($asset['firmware_version'] ?? ''));
        $currentFirmware = trim((string)($data['firmware_version'] ?? ''));
        if ($previousFirmware === $currentFirmware) {
            return;
        }

        $this->ensureDeviceMaintenanceHistoryTable();
        $stmt = $this->db->prepare(
            'INSERT INTO device_maintenance_history
                (computer_id, field_name, display_name, previous_value, current_value, changed_at, created_at)
             VALUES (?, "firmware_version", "Firmware Version", ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $deviceId,
            $previousFirmware === '' ? null : $previousFirmware,
            $currentFirmware === '' ? null : $currentFirmware,
        ]);
        $this->completeAssignmentsForMaintenance($deviceId, 'firmware_version', (int)$this->db->lastInsertId());
    }

    private function recordOfflineDeviceNameChange(int $deviceId, array $asset, array $data): void
    {
        $previousName = trim((string)($asset['device_name'] ?? ''));
        $currentName = trim((string)($data['device_name'] ?? ''));
        if ($previousName === '' || $currentName === '' || $previousName === $currentName) {
            return;
        }

        $this->ensureDeviceMaintenanceHistoryTable();
        $stmt = $this->db->prepare(
            'INSERT INTO device_maintenance_history
                (computer_id, field_name, display_name, previous_value, current_value, changed_at, created_at)
             VALUES (?, "device_name", "Device Name", ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$deviceId, $previousName, $currentName]);
        $this->completeAssignmentsForMaintenance($deviceId, 'device_name', (int)$this->db->lastInsertId());
    }

    private function employeeOptions(array $user): array
    {
        if (ScopeService::isAdmin($user)) {
            return $this->db->query(
                'SELECT employees.*, offices.name AS office_name
                 FROM employees
                 LEFT JOIN offices ON offices.id = employees.office_id
                 WHERE employees.office_id IS NOT NULL
                 ORDER BY offices.name, employees.name'
            )->fetchAll();
        }

        $officeIds = ScopeService::officeIdsForUser($user);
        if (!$officeIds) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT employees.*, offices.name AS office_name
             FROM employees
             LEFT JOIN offices ON offices.id = employees.office_id
             WHERE employees.office_id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')
             ORDER BY offices.name, employees.name'
        );
        $stmt->execute($officeIds);
        return $stmt->fetchAll();
    }

    private function officeOptions(array $user): array
    {
        if (ScopeService::isAdmin($user)) {
            return $this->db->query('SELECT * FROM offices ORDER BY name')->fetchAll();
        }

        $officeIds = ScopeService::officeIdsForUser($user);
        if (!$officeIds) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM offices WHERE id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ') ORDER BY name'
        );
        $stmt->execute($officeIds);
        return $stmt->fetchAll();
    }

    private function updateAssignmentTaskMap(): array
    {
        return [
            'windows_update' => 'os_version',
            'bios_update' => 'bios_version',
            'firmware_update' => 'firmware_version',
            'ram_upgrade' => 'ram_size',
            'hard_drive_upgrade' => 'hard_drive_size',
            'device_name_change' => 'device_name',
            'battery_replace' => 'battery_health',
            'software_update' => 'software_update',
        ];
    }

    private function completeAssignmentsForMaintenance(int $deviceId, string $fieldName, int $evidenceId): void
    {
        $taskTypes = [];
        foreach ($this->updateAssignmentTaskMap() as $taskType => $mappedField) {
            if ($mappedField === $fieldName) {
                $taskTypes[] = $taskType;
            }
        }
        if (!$taskTypes) {
            return;
        }

        $this->ensureUpdateAssignmentsTable();
        $placeholders = implode(',', array_fill(0, count($taskTypes), '?'));
        $stmt = $this->db->prepare(
            "UPDATE update_assignments
             SET status = \"completed\", completed_at = NOW(), evidence_type = \"maintenance\", evidence_id = ?, updated_at = NOW()
             WHERE computer_id = ? AND status = \"pending\" AND task_type IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$evidenceId, $deviceId], $taskTypes));
    }

    private function findScopedAsset(int $id, array $user): ?array
    {
        NetworkPrinterService::schema($this->db);
        $pending = $this->one('SELECT computers.* FROM computers JOIN network_printer_links np ON np.computer_id = computers.id WHERE computers.id = ? AND computers.office_id IS NULL', [$id]);
        if ($pending && NetworkPrinterService::canAccessUnassigned($this->db, $id, $user)) return $pending;
        [$scopeWhere, $scopeParams] = ScopeService::assetWhere($user);
        $stmt = $this->db->prepare('SELECT * FROM computers WHERE id = ? AND ' . $scopeWhere);
        $stmt->execute(array_merge([$id], $scopeParams));
        $asset = $stmt->fetch();
        return $asset ?: null;
    }

    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function assigneeCanReceiveOffice(array $assignee, int $officeId): bool
    {
        if (($assignee['role_slug'] ?? '') === 'admin' && empty($assignee['office_id'])) {
            return true;
        }

        return !empty($assignee['office_id'])
            && in_array($officeId, ScopeService::descendantOfficeIds((int)$assignee['office_id']), true);
    }

    private function canManageAssignments(array $user): bool
    {
        return ($user['role_slug'] ?? '') === 'super-admin'
            || ($user['role_slug'] ?? '') === 'admin';
    }

    private function officeColumnScope(array $user, string $column): array
    {
        if (ScopeService::isAdmin($user)) {
            return ['1=1', []];
        }

        $officeIds = ScopeService::officeIdsForUser($user);
        if (!$officeIds) {
            return ['1=0', []];
        }

        return [
            $column . ' IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')',
            $officeIds,
        ];
    }

    private function selectedIds(): array
    {
        $rawIds = Request::input('ids', []);
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $rawIds[] = Request::input('id', 0);
        $ids = array_map('intval', $rawIds);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    private function ensureSoftwareSyncRequestsTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS software_sync_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                computer_id BIGINT UNSIGNED NOT NULL,
                requested_by_user_id INT UNSIGNED NULL,
                requested_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_software_sync_computer (computer_id),
                KEY idx_software_sync_pending (computer_id, completed_at),
                CONSTRAINT fk_software_sync_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE,
                CONSTRAINT fk_software_sync_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureDriverSyncRequestsTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS driver_sync_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                computer_id BIGINT UNSIGNED NOT NULL,
                requested_by_user_id INT UNSIGNED NULL,
                requested_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_driver_sync_computer (computer_id),
                KEY idx_driver_sync_pending (computer_id, completed_at),
                CONSTRAINT fk_driver_sync_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE,
                CONSTRAINT fk_driver_sync_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureInstalledDriversTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS installed_drivers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                computer_id BIGINT UNSIGNED NOT NULL,
                device_name VARCHAR(255) NOT NULL,
                version VARCHAR(120) NULL,
                provider VARCHAR(190) NULL,
                manufacturer VARCHAR(190) NULL,
                driver_date DATE NULL,
                installed_date DATE NULL,
                last_updated_date DATE NULL,
                inf_name VARCHAR(190) NULL,
                device_class VARCHAR(120) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_driver_computer (computer_id),
                KEY idx_driver_device (device_name),
                CONSTRAINT fk_driver_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensureInstalledDriverColumn('installed_date', 'DATE NULL AFTER driver_date');
        $this->ensureInstalledDriverColumn('last_updated_date', 'DATE NULL AFTER installed_date');
    }

    private function ensureDriverHistoryTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS driver_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                computer_id BIGINT UNSIGNED NOT NULL,
                device_name VARCHAR(255) NOT NULL,
                previous_version VARCHAR(120) NULL,
                current_version VARCHAR(120) NULL,
                change_type ENUM("installed", "updated", "removed") NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_driver_history_computer (computer_id),
                KEY idx_driver_history_device (device_name),
                CONSTRAINT fk_driver_history_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureInstalledDriverColumn(string $column, string $definition): void
    {
        return;
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "installed_drivers"
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->db->exec('ALTER TABLE installed_drivers ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function ensureDeviceMaintenanceHistoryTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS device_maintenance_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                computer_id BIGINT UNSIGNED NOT NULL,
                field_name VARCHAR(80) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                previous_value TEXT NULL,
                current_value TEXT NULL,
                changed_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_device_maintenance_computer (computer_id),
                KEY idx_device_maintenance_changed (changed_at),
                CONSTRAINT fk_device_maintenance_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureDeviceMaintenanceHiddenRecordsTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS device_maintenance_hidden_records (
                maintenance_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (maintenance_id, user_id),
                KEY idx_maintenance_hidden_user (user_id),
                CONSTRAINT fk_maintenance_hidden_record FOREIGN KEY (maintenance_id) REFERENCES device_maintenance_history(id) ON DELETE CASCADE,
                CONSTRAINT fk_maintenance_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureUpdateAssignmentsTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS update_assignments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                assignment_group_key VARCHAR(40) NULL,
                computer_id BIGINT UNSIGNED NOT NULL,
                task_kind ENUM("device", "software") NOT NULL,
                task_type VARCHAR(80) NULL,
                software_name VARCHAR(255) NULL,
                assigned_to_user_id INT UNSIGNED NOT NULL,
                assigned_by_user_id INT UNSIGNED NULL,
                note TEXT NULL,
                status ENUM("pending", "completed") NOT NULL DEFAULT "pending",
                completed_at DATETIME NULL,
                evidence_type VARCHAR(40) NULL,
                evidence_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_update_assignment_assignee (assigned_to_user_id, status),
                KEY idx_update_assignment_computer (computer_id, status),
                CONSTRAINT fk_update_assignment_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE,
                CONSTRAINT fk_update_assignment_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_update_assignment_assigner FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensureUpdateAssignmentColumn('assignment_group_key', 'VARCHAR(40) NULL AFTER id');
        $this->ensureUpdateAssignmentColumn('task_type', 'VARCHAR(80) NULL AFTER task_kind');
        $this->ensureUpdateAssignmentColumn('software_name', 'VARCHAR(255) NULL AFTER task_type');
        $this->ensureUpdateAssignmentColumn('evidence_type', 'VARCHAR(40) NULL AFTER completed_at');
        $this->ensureUpdateAssignmentColumn('evidence_id', 'BIGINT UNSIGNED NULL AFTER evidence_type');
    }

    private function ensureUpdateAssignmentColumn(string $column, string $definition): void
    {
        return;
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "update_assignments"
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->db->exec('ALTER TABLE update_assignments ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

}
