<?php

class NetworkDeviceController extends Controller
{
    private function schema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS network_collectors (
            computer_id BIGINT PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB');
        $this->db->exec('CREATE TABLE IF NOT EXISTS network_devices (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, collector_id BIGINT NOT NULL,
            address VARCHAR(45) NOT NULL, details JSON NULL, reachable TINYINT NOT NULL DEFAULT 0,
            checked_at DATETIME NOT NULL, last_seen_at DATETIME NULL,
            UNIQUE KEY collector_address (collector_id, address)
        ) ENGINE=InnoDB');
        NetworkPrinterService::schema($this->db);
        $this->db->exec('CREATE TABLE IF NOT EXISTS network_device_sync_requests (
            computer_id BIGINT PRIMARY KEY, requested_at DATETIME NOT NULL, completed_at DATETIME NULL,
            FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
    }

    public function syncDevice(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        $this->schema();
        $id = (int)Request::input('device_id');
        [$where, $params] = ScopeService::assetWhere($user, 'computers');
        $stmt = $this->db->prepare('SELECT computers.id FROM computers JOIN network_printer_links np ON np.computer_id = computers.id WHERE computers.id = ? AND ' . $where);
        $stmt->execute([$id, ...$params]);
        if (!$stmt->fetchColumn()) { http_response_code(404); exit; }
        $stmt = $this->db->prepare('INSERT INTO network_device_sync_requests (computer_id, requested_at, completed_at) VALUES (?, NOW(), NULL) ON DUPLICATE KEY UPDATE requested_at = NOW(), completed_at = NULL');
        $stmt->execute([$id]);
        Audit::log('requested_network_device_sync', 'device', $id);
        if (Request::expectsJson()) {
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            Response::json(['ok' => true, 'statusUrl' => ($basePath ?: '') . '/device/network-device-sync-status?device_id=' . $id, 'message' => 'Network device sync started.']);
        }
        $this->flash('success', 'Network device sync requested. The collector will refresh this device shortly.');
        Response::redirect('/device');
    }

    public function syncStatus(): void
    {
        $user = $this->requirePermission('assets.view');
        $this->schema();
        $id = (int)Request::input('device_id');
        [$where, $params] = ScopeService::assetWhere($user, 'computers');
        $stmt = $this->db->prepare('SELECT sr.completed_at, computers.model, computers.device_name, computers.serial_number, computers.firmware_version, np.address, np.reachable, np.state, nd.details
            FROM network_device_sync_requests sr
            JOIN computers ON computers.id = sr.computer_id
            JOIN network_printer_links np ON np.computer_id = computers.id
            LEFT JOIN network_devices nd ON nd.collector_id = np.collector_id AND nd.address = np.address
            WHERE computers.id = ? AND ' . $where . ' LIMIT 1');
        $stmt->execute([$id, ...$params]);
        $row = $stmt->fetch();
        if (!$row) Response::json(['ok' => false, 'error' => 'Sync request was not found.'], 404);
        Response::json(['ok' => true, 'status' => $row['completed_at'] ? 'completed' : 'pending', 'device' => [
            'item' => $row['model'] ?: $row['device_name'] ?: 'Network Device', 'ip' => $row['address'],
            'serial' => $row['serial_number'] ?: '-', 'firmware' => $row['firmware_version'] ?: 'Awaiting reading',
            'online' => (bool)$row['reachable'], 'details' => json_decode($row['details'] ?? '{}', true) ?: [],
        ]]);
    }

    public function addDevice(): void
    {
        $user = $this->requirePermission('assets.manage');
        $this->requirePermission('offices.manage');
        Csrf::verify();
        $this->schema();
        try {
            $id = NetworkPrinterService::add($this->db, (int)Request::input('collector_id'), trim((string)Request::input('address')), $user);
        } catch (InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            Response::redirect('/device');
            return;
        }
        Audit::log('added_network_device', 'device', $id);
        $this->flash('success', 'Network device is registered and is now in Unassigned Devices. The collector will identify its type and refresh its details automatically.');
        Response::redirect('/unassigned-devices?status=network');
    }

    public function printerTargets(): void
    {
        $auth = Request::header('Authorization') ?? '';
        if (!preg_match('/^Bearer ([a-zA-Z0-9]+)$/', $auth, $m)) Response::json(['error' => 'Unauthorized'], 401);
        $stmt = $this->db->prepare('SELECT id FROM computers WHERE agent_token_hash = ? AND agent_uninstalled_at IS NULL');
        $stmt->execute([hash('sha256', $m[1])]);
        $id = $stmt->fetchColumn();
        if (!$id) Response::json(['error' => 'Unauthorized'], 401);
        $this->touchCollectorActivity((int)$id);
        if (!RateLimitService::allow('network-targets:' . $id, 10, 60)) Response::json(['error' => 'Too many requests'], 429);
        $this->schema();
        $stmt = $this->db->prepare('SELECT enabled FROM network_collectors WHERE computer_id = ?');
        $stmt->execute([$id]);
        if (!(int)$stmt->fetchColumn()) Response::json(['error' => 'Collector is disabled'], 403);
        $stmt = $this->db->prepare('SELECT address FROM network_printer_links WHERE collector_id = ? ORDER BY created_at, computer_id LIMIT 64');
        $stmt->execute([$id]);
        $addresses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $this->db->prepare('SELECT np.address FROM network_printer_links np JOIN network_device_sync_requests sr ON sr.computer_id = np.computer_id WHERE np.collector_id = ? AND sr.completed_at IS NULL ORDER BY sr.requested_at LIMIT 64');
        $stmt->execute([$id]);
        Response::json(['addresses' => $addresses, 'syncAddresses' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    }

    public function index(): void
    {
        $user = $this->requirePermission('assets.view');
        $this->schema();
        [$where, $params] = ScopeService::assetWhere($user);
        // Interpret DATETIME in the same database timezone that wrote NOW().
        // Unix timestamps let the view display it in the application timezone safely.
        $stmt = $this->db->prepare("SELECT n.*, c.computer_name, o.name AS office_name,
            UNIX_TIMESTAMP(n.checked_at) AS checked_at_epoch, UNIX_TIMESTAMP() AS database_now_epoch
            FROM network_devices n JOIN computers c ON c.id = n.collector_id
            LEFT JOIN offices o ON o.id = c.office_id
            WHERE n.collector_id IN (SELECT id FROM computers WHERE {$where}) ORDER BY o.name, n.address LIMIT 2000");
        $stmt->execute($params);
        $networkDevices = $stmt->fetchAll();
        $collectors = [];
        if (ScopeService::isAdmin($user)) {
            $collectors = $this->db->query('SELECT computers.id, computer_name, COALESCE(network_collectors.enabled, 0) AS enabled
                FROM computers LEFT JOIN network_collectors ON computer_id = computers.id
                WHERE agent_token_hash IS NOT NULL ORDER BY computer_name')->fetchAll();
        }
        $this->view('network-devices/index', compact('networkDevices', 'collectors') + ['title' => 'Network Devices']);
    }

    public function configure(): void
    {
        $user = $this->requireAuth();
        Csrf::verify();
        $this->schema();
        $id = (int)Request::input('computer_id');
        $officeId = (int)Request::input('office_id');
        $offlineAfter = max(1, (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes']);
        if (!ScopeService::isAdmin($user)) {
            $accessibleOfficeIds = !empty($user['office_id'])
                ? ScopeService::descendantOfficeIds((int)$user['office_id'])
                : [];
            if (!in_array($officeId, $accessibleOfficeIds, true)) {
                http_response_code(403);
                exit;
            }
        }
        $stmt = $this->db->prepare("SELECT computers.id FROM computers JOIN network_collectors ON network_collectors.computer_id = computers.id WHERE computers.id = ? AND computers.office_id = ? AND computers.agent_token_hash IS NOT NULL AND computers.agent_uninstalled_at IS NULL AND computers.last_checkin_at >= NOW() - INTERVAL {$offlineAfter} MINUTE");
        $stmt->execute([$id, $officeId]);
        if (!$stmt->fetch()) { http_response_code(422); exit; }
        $enabled = Request::input('enabled') === '1' ? 1 : 0;
        $this->db->beginTransaction();
        try {
            if ($enabled) {
                // An office has one active collector. Move its existing managed
                // devices to the newly selected collector so they do not remain
                // attached to an old or reinstalled collector computer.
                $stmt = $this->db->prepare('UPDATE network_collectors nc
                    JOIN computers c ON c.id = nc.computer_id
                    SET nc.enabled = 0
                    WHERE c.office_id = ? AND nc.computer_id <> ?');
                $stmt->execute([$officeId, $id]);

                $stmt = $this->db->prepare('UPDATE network_printer_links np
                    JOIN computers previous_collector ON previous_collector.id = np.collector_id
                    SET np.collector_id = ?, np.reachable = 0, np.state = "queued", np.checked_at = NULL
                    WHERE previous_collector.office_id = ? AND np.collector_id <> ?');
                $stmt->execute([$id, $officeId, $id]);
            }

            $stmt = $this->db->prepare('INSERT INTO network_collectors (computer_id, enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
            $stmt->execute([$id, $enabled]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        Audit::log('configured_network_collector', 'device', $id, ['enabled' => $enabled]);
        $this->flash('success', 'Collector authorization updated. Configure SNMP locally on that computer.');
        Response::redirect('/dashboard');
    }

    public function upload(): void
    {
        $auth = Request::header('Authorization') ?? '';
        if (!preg_match('/^Bearer ([a-zA-Z0-9]+)$/', $auth, $m)) Response::json(['error' => 'Unauthorized'], 401);
        $stmt = $this->db->prepare('SELECT id FROM computers WHERE agent_token_hash = ? AND agent_uninstalled_at IS NULL');
        $stmt->execute([hash('sha256', $m[1])]);
        $collector = $stmt->fetch();
        if (!$collector) Response::json(['error' => 'Unauthorized'], 401);
        $this->touchCollectorActivity((int)$collector['id']);
        if (!RateLimitService::allow('network-upload:' . $collector['id'], 4, 60)) Response::json(['error' => 'Too many requests'], 429);
        $this->schema();
        $stmt = $this->db->prepare('SELECT enabled FROM network_collectors WHERE computer_id = ?');
        $stmt->execute([$collector['id']]);
        if (!(int)$stmt->fetchColumn()) Response::json(['error' => 'Collector is not enabled by an administrator'], 403);
        $payload = Request::json(262144);
        $devices = $payload['devices'] ?? null;
        if (!is_array($devices) || count($devices) > 64) Response::json(['error' => 'Invalid device batch'], 422);
        $validated = [];
        foreach ($devices as $device) {
            if (!is_array($device) || !filter_var($device['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !is_bool($device['reachable'] ?? null)) Response::json(['error' => 'Invalid device'], 422);
            $details = [];
            foreach (['name', 'kind', 'description', 'model', 'serial', 'firmware'] as $key) {
                $value = $device[$key] ?? '';
                if (!is_string($value) || strlen($value) > 4096) Response::json(['error' => 'Invalid device field'], 422);
                $details[$key] = $value;
            }
            $supplies = $device['supplies'] ?? [];
            if (!is_array($supplies) || count($supplies) > 16) Response::json(['error' => 'Invalid supplies'], 422);
            $details['supplies'] = [];
            foreach ($supplies as $supply) {
                if (!is_array($supply) || !is_string($supply['name'] ?? null) || strlen($supply['name']) > 1024) Response::json(['error' => 'Invalid supply'], 422);
                $percent = $supply['percent'] ?? null;
                if ($percent !== null && (!is_numeric($percent) || $percent < 0 || $percent > 100)) Response::json(['error' => 'Invalid supply level'], 422);
                $details['supplies'][] = ['name' => $supply['name'], 'percent' => $percent];
            }
            $validated[] = [$device['address'], $device['reachable'], json_encode($details, JSON_THROW_ON_ERROR)];
        }
        $this->db->beginTransaction();
        try {
            $existingDetails = $this->db->prepare(
                'SELECT details FROM network_devices WHERE collector_id = ? AND address = ? LIMIT 1'
            );
            $stmt = $this->db->prepare('INSERT INTO network_devices (collector_id, address, reachable, details, checked_at, last_seen_at)
                VALUES (?, ?, ?, ?, NOW(), IF(? = 1, NOW(), NULL))
                ON DUPLICATE KEY UPDATE reachable = VALUES(reachable), checked_at = NOW(),
                details = IF(VALUES(reachable) = 1, VALUES(details), details),
                last_seen_at = IF(VALUES(reachable) = 1, NOW(), last_seen_at)');
            foreach ($validated as [$address, $reachable, $details]) {
                if ($reachable) {
                    $existingDetails->execute([$collector['id'], $address]);
                    $previous = json_decode((string)($existingDetails->fetchColumn() ?: '{}'), true) ?: [];
                    $incoming = json_decode($details, true) ?: [];
                    $details = json_encode($this->preserveNetworkDetails($incoming, $previous), JSON_THROW_ON_ERROR);
                }
                $stmt->execute([$collector['id'], $address, (int)$reachable, $details, (int)$reachable]);
                NetworkPrinterService::applyReport($this->db, (int)$collector['id'], $address, $reachable, json_decode($details, true));
                $done = $this->db->prepare('UPDATE network_device_sync_requests sr JOIN network_printer_links np ON np.computer_id = sr.computer_id SET sr.completed_at = NOW() WHERE np.collector_id = ? AND np.address = ? AND sr.completed_at IS NULL');
                $done->execute([(int)$collector['id'], $address]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        Response::json(['ok' => true]);
    }

    private function preserveNetworkDetails(array $incoming, array $previous): array
    {
        foreach (['name', 'kind', 'description', 'model', 'serial', 'firmware'] as $key) {
            if (trim((string)($incoming[$key] ?? '')) === '' && trim((string)($previous[$key] ?? '')) !== '') {
                $incoming[$key] = $previous[$key];
            }
        }

        if (empty($incoming['supplies']) && !empty($previous['supplies']) && is_array($previous['supplies'])) {
            $incoming['supplies'] = $previous['supplies'];
        }

        return $incoming;
    }

    private function touchCollectorActivity(int $computerId): void
    {
        // Fetching targets or uploading SNMP results is authenticated agent activity,
        // so it must keep the collector computer online just like a heartbeat does.
        $stmt = $this->db->prepare(
            'UPDATE computers
             SET agent_uninstalled_at = NULL, last_checkin_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$computerId]);
    }
}
