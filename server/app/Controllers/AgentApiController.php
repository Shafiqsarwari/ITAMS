<?php

class AgentApiController extends Controller
{
    public function register(): void
    {
        $config = require __DIR__ . '/../../config/app.php';
        $this->enforceRateLimit(
            'agent-register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            (int)$config['agent_registration_rate_limit_per_5_minutes'],
            300
        );
        $registrationKey = AppSettingService::get('agent_registration_key') ?? $config['agent_registration_key'];
        if (strlen($registrationKey) < 32
            || !hash_equals($registrationKey, Request::header('X-Registration-Key') ?? '')
        ) {
            Response::json(['error' => 'Unauthorized registration key'], 401);
        }

        $payload = Request::json((int)$config['agent_max_json_bytes']);
        $computerName = $this->boundedString($payload['computerName'] ?? null, 150);
        $serial = $this->boundedString($payload['serialNumber'] ?? null, 150);
        $collectorComputer = filter_var($payload['collectorComputer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($computerName === '') {
            Response::json(['error' => 'computerName is required'], 422);
        }

        $existing = $this->computerByName($computerName);

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE computers
                 SET device_name = COALESCE(device_name, ?), device_type = \'Computer\', serial_number = COALESCE(?, serial_number),
                     agent_token_hash = ?, agent_version = ?, is_offline_device = 0, agent_uninstalled_at = NULL,
                     last_checkin_at = NOW(), updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $computerName,
                $serial ?: null,
                $tokenHash,
                $this->boundedString($payload['agentVersion'] ?? null, 80) ?: null,
                $existing['id'],
            ]);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO computers (computer_name, device_name, device_type, serial_number, agent_token_hash, agent_version, is_offline_device, last_checkin_at, created_at, updated_at)
                 VALUES (?, ?, "Computer", ?, ?, ?, 0, NOW(), NOW(), NOW())'
            );
            $stmt->execute([
                $computerName,
                $computerName,
                $serial ?: null,
                $tokenHash,
                $this->boundedString($payload['agentVersion'] ?? null, 80) ?: null,
            ]);
        }
        $computerId = (int)($existing['id'] ?? $this->db->lastInsertId());
        $this->reconcileCollectorState($computerId, $collectorComputer);
        Audit::log('registered', 'agent', null, ['computer' => $computerName]);
        Response::json([
            'token' => $token,
        ]);
    }

    public function heartbeat(): void
    {
        $computer = $this->authenticateAgent();
        $app = require __DIR__ . '/../../config/app.php';
        $this->enforceRateLimit('agent-heartbeat:' . (int)$computer['id'], (int)$app['agent_heartbeat_rate_limit_per_minute'], 60);
        $payload = Request::json((int)$app['agent_max_json_bytes']);
        if (array_key_exists('collectorComputer', $payload)) {
            $this->reconcileCollectorState(
                (int)$computer['id'],
                filter_var($payload['collectorComputer'], FILTER_VALIDATE_BOOLEAN)
            );
        }
        $this->ensureSoftwareSyncRequestsTable();
        $this->ensureDriverSyncRequestsTable();
        $syncRequested = $this->hasPendingSoftwareSync((int)$computer['id']) || $this->hasPendingDriverSync((int)$computer['id']);
        $stmt = $this->db->prepare(
            'UPDATE computers SET agent_version = COALESCE(?, agent_version), agent_uninstalled_at = NULL, last_checkin_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$payload['agentVersion'] ?? null, $computer['id']]);
        Response::json(['ok' => true, 'serverTime' => date(DATE_ATOM), 'syncInventory' => $syncRequested]);
    }

    private function reconcileCollectorState(int $computerId, bool $collectorComputer): void
    {
        NetworkPrinterService::schema($this->db);
        if ($collectorComputer) {
            // Existing authorization is retained across upgrades; a new collector
            // still requires an administrator to enable it.
            $stmt = $this->db->prepare('INSERT INTO network_collectors (computer_id, enabled) VALUES (?, 0) ON DUPLICATE KEY UPDATE computer_id = VALUES(computer_id)');
            $stmt->execute([$computerId]);
            return;
        }

        // Removing collector mode must immediately invalidate its cached reachability
        // and remove the computer from the collector management list.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE network_devices SET reachable = 0, checked_at = NOW() WHERE collector_id = ?');
            $stmt->execute([$computerId]);
            $stmt = $this->db->prepare('UPDATE network_printer_links SET reachable = 0, state = "unavailable", checked_at = NOW() WHERE collector_id = ?');
            $stmt->execute([$computerId]);
            $stmt = $this->db->prepare('DELETE FROM network_collectors WHERE computer_id = ?');
            $stmt->execute([$computerId]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function offline(): void
    {
        $computer = $this->authenticateAgent();
        $app = require __DIR__ . '/../../config/app.php';
        $this->enforceRateLimit('agent-offline:' . (int)$computer['id'], (int)$app['agent_heartbeat_rate_limit_per_minute'], 60);
        $offlineAfter = max(1, (int)$app['offline_after_minutes']);
        $offlineMinutes = $offlineAfter + 1;
        NetworkPrinterService::schema($this->db);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE computers
                 SET agent_uninstalled_at = NOW(), last_checkin_at = NOW() - INTERVAL {$offlineMinutes} MINUTE, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$computer['id']]);
            $stmt = $this->db->prepare('UPDATE network_devices SET reachable = 0, checked_at = NOW() WHERE collector_id = ?');
            $stmt->execute([$computer['id']]);
            $stmt = $this->db->prepare('UPDATE network_printer_links SET reachable = 0, state = "unavailable", checked_at = NOW() WHERE collector_id = ?');
            $stmt->execute([$computer['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        Audit::log('offline', 'agent', (int)$computer['id'], ['reason' => 'agent_uninstall_or_shutdown']);
        Response::json(['ok' => true, 'serverTime' => date(DATE_ATOM)]);
    }

    public function inventory(): void
    {
        $computer = $this->authenticateAgent();
        $app = require __DIR__ . '/../../config/app.php';
        $this->enforceRateLimit('agent-inventory:' . (int)$computer['id'], (int)$app['agent_inventory_rate_limit_per_minute'], 60);
        $payload = Request::json((int)$app['agent_max_json_bytes']);
        $this->assertInventoryBounds($payload, $app);
        $eventId = trim((string)(Request::header('X-Agent-Event-Id') ?? ''));
        if ($eventId !== '' && $this->hasProcessedEvent($eventId)) {
            Response::json(['ok' => true, 'duplicate' => true]);
        }

        $this->ensureSoftwareSyncRequestsTable();
        $this->ensureDriverSyncRequestsTable();
        $this->ensureDeviceMaintenanceHistoryTable();
        $this->ensureUpdateAssignmentsTable();
        $this->ensureComputerBatteryHealthColumn();
        $this->ensureInstalledDriversTable();
        $this->ensureDriverHistoryTable();
        $this->db->beginTransaction();
        try {
            $this->recordMaintenanceChanges((int)$computer['id'], $payload);
            $this->updateComputer($computer['id'], $payload);
            $this->replaceBios($computer['id'], $payload['bios'] ?? []);
            $this->replaceNetwork($computer['id'], $payload['network'] ?? []);
            $this->replaceSoftware($computer['id'], $payload['software'] ?? []);
            $this->replaceDrivers($computer['id'], $payload['drivers'] ?? []);
            $this->completeSoftwareSyncRequests((int)$computer['id']);
            $this->completeDriverSyncRequests((int)$computer['id']);
            if ($eventId !== '') {
                $this->recordAgentEvent($computer['id'], $eventId, 'inventory.changed', $payload);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log((string)$e);
            Response::json(['error' => 'Inventory failed'], 500);
        }

        Response::json(['ok' => true]);
    }

    public function logs(): void
    {
        $computer = $this->authenticateAgent();
        $app = require __DIR__ . '/../../config/app.php';
        $this->enforceRateLimit('agent-logs:' . (int)$computer['id'], (int)$app['agent_log_rate_limit_per_minute'], 60);
        $payload = Request::json((int)$app['agent_max_json_bytes']);
        $logs = $payload['logs'] ?? [];
        if (!is_array($logs) || count($logs) > (int)$app['agent_max_logs_per_request']) {
            Response::json(['error' => 'Invalid log batch'], 413);
        }
        foreach ($logs as $log) {
            if (!is_array($log)) {
                continue;
            }
            $context = json_encode($log['context'] ?? [], JSON_UNESCAPED_SLASHES);
            if (!is_string($context) || strlen($context) > (int)$app['agent_max_log_context_bytes']) {
                $context = null;
            }
            $stmt = $this->db->prepare(
                'INSERT INTO agent_logs (computer_id, level, message, context, created_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $computer['id'],
                $this->boundedString($log['level'] ?? 'info', 20),
                $this->boundedString($log['message'] ?? '', 1000),
                $context,
            ]);
        }
        Response::json(['ok' => true]);
    }

    private function authenticateAgent(): array
    {
        $auth = Request::header('Authorization') ?? '';
        if (!preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            Response::json(['error' => 'Missing token'], 401);
        }
        $hash = hash('sha256', trim($matches[1]));
        $stmt = $this->db->prepare('SELECT * FROM computers WHERE agent_token_hash = ?');
        $stmt->execute([$hash]);
        $computer = $stmt->fetch();
        if (!$computer) {
            Response::json(['error' => 'Invalid token'], 401);
        }
        return $computer;
    }

    private function computerByName(string $computerName): ?array
    {
        $stmt = $this->db->prepare('SELECT id, agent_token_hash FROM computers WHERE computer_name = ? LIMIT 1');
        $stmt->execute([$computerName]);
        return $stmt->fetch() ?: null;
    }

    private function assertInventoryBounds(array $payload, array $app): void
    {
        $networks = $payload['network'] ?? ($payload['networks'] ?? []);
        $software = $payload['software'] ?? [];
        $drivers = $payload['drivers'] ?? [];
        if (!is_array($networks)
            || !is_array($software)
            || !is_array($drivers)
            || count($networks) > (int)$app['agent_max_networks_per_inventory']
            || count($software) > (int)$app['agent_max_software_per_inventory']
            || count($drivers) > (int)$app['agent_max_drivers_per_inventory']
        ) {
            Response::json(['error' => 'Inventory payload exceeds limits'], 413);
        }
    }

    private function boundedString(mixed $value, int $length): string
    {
        return substr(trim((string)$value), 0, $length);
    }

    private function enforceRateLimit(string $bucket, int $limit, int $windowSeconds): void
    {
        if (!RateLimitService::allow($bucket, $limit, $windowSeconds)) {
            header('Retry-After: ' . $windowSeconds);
            Response::json(['error' => 'Rate limit exceeded'], 429);
        }
    }

    private function hasProcessedEvent(string $eventId): bool
    {
        $this->ensureAgentEventsTable();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM agent_events WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function recordAgentEvent(int $computerId, string $eventId, string $eventType, array $payload): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO agent_events (event_id, computer_id, event_type, payload_hash, observed_at, processed_at, created_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $eventId,
            $computerId,
            $eventType,
            hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            $this->agentObservedAt(),
        ]);
    }

    private function agentObservedAt(): string
    {
        $observedHeader = Request::header('X-Agent-Observed-At');
        $observedTime = $observedHeader ? strtotime($observedHeader) : false;
        return $observedTime ? date('Y-m-d H:i:s', $observedTime) : date('Y-m-d H:i:s');
    }

    private function ensureAgentEventsTable(): void
    {
        return;
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS agent_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(120) NOT NULL,
                computer_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                payload_hash CHAR(64) NOT NULL,
                observed_at DATETIME NULL,
                processed_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_agent_events_event_id (event_id),
                KEY idx_agent_events_computer (computer_id),
                KEY idx_agent_events_type (event_type),
                CONSTRAINT fk_agent_events_computer FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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

    private function completeAssignmentsForMaintenance(int $computerId, string $fieldName, int $evidenceId): void
    {
        $fieldTasks = [
            'os_version' => 'windows_update',
            'bios_version' => 'bios_update',
            'ram_size' => 'ram_upgrade',
            'hard_drive_size' => 'hard_drive_upgrade',
            'device_name' => 'device_name_change',
            'battery_health' => 'battery_replace',
        ];
        if (!isset($fieldTasks[$fieldName])) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE update_assignments
             SET status = \'completed\', completed_at = NOW(), evidence_type = \'maintenance\', evidence_id = ?, updated_at = NOW()
             WHERE computer_id = ? AND status = \'pending\' AND task_type = ?'
        );
        $stmt->execute([$evidenceId, $computerId, $fieldTasks[$fieldName]]);
    }

    private function completeAssignmentsForSoftware(int $computerId, string $softwareName, int $evidenceId): void
    {
        $pending = $this->db->prepare(
            'SELECT id, software_name
             FROM update_assignments
             WHERE computer_id = ? AND status = \'pending\' AND task_type = \'software_update\''
        );
        $pending->execute([$computerId]);
        $reportedKey = $this->softwareTaskKey($softwareName);
        $matchingIds = [];
        foreach ($pending->fetchAll() as $assignment) {
            $assignedName = trim((string)($assignment['software_name'] ?? ''));
            if ($assignedName === '' || hash_equals($this->softwareTaskKey($assignedName), $reportedKey)) {
                $matchingIds[] = (int)$assignment['id'];
            }
        }
        if (!$matchingIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($matchingIds), '?'));
        $stmt = $this->db->prepare(
            "UPDATE update_assignments
             SET status = 'completed', completed_at = NOW(), evidence_type = 'software_history', evidence_id = ?, updated_at = NOW()
             WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $stmt->execute(array_merge([$evidenceId], $matchingIds));
    }

    private function ensureComputerBatteryHealthColumn(): void
    {
        return;
        $exists = $this->db->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'computers'
               AND COLUMN_NAME = 'battery_health_percent'"
        )->fetchColumn();

        if ((int)$exists === 0) {
            $this->db->exec('ALTER TABLE computers ADD COLUMN battery_health_percent TINYINT UNSIGNED NULL AFTER cpu_name');
        }
    }

    private function hasPendingSoftwareSync(int $computerId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM software_sync_requests WHERE computer_id = ? AND completed_at IS NULL');
        $stmt->execute([$computerId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasPendingDriverSync(int $computerId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM driver_sync_requests WHERE computer_id = ? AND completed_at IS NULL');
        $stmt->execute([$computerId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function completeSoftwareSyncRequests(int $computerId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE software_sync_requests
             SET completed_at = NOW(), updated_at = NOW()
             WHERE computer_id = ? AND completed_at IS NULL'
        );
        $stmt->execute([$computerId]);
    }

    private function completeDriverSyncRequests(int $computerId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE driver_sync_requests
             SET completed_at = NOW(), updated_at = NOW()
             WHERE computer_id = ? AND completed_at IS NULL'
        );
        $stmt->execute([$computerId]);
    }

    private function updateComputer(int $id, array $payload): void
    {
        $hardware = $payload['hardware'] ?? [];
        $diskSummary = $this->trustedDiskSummary($hardware['disks'] ?? []);
        $primaryMacAddress = $this->primaryMacAddress($payload['network'] ?? ($payload['networks'] ?? []));
        $stmt = $this->db->prepare(
            'UPDATE computers SET computer_name = ?, device_name = COALESCE(device_name, ?), device_type = \'Computer\', is_offline_device = 0,
             os_name = ?, os_version = ?, build_number = ?, manufacturer = ?,
             model = ?, serial_number = ?, domain_workgroup = ?, cpu_name = ?, ram_bytes = ?, disk_summary = COALESCE(?, disk_summary),
             motherboard = ?, battery_health_percent = ?, agent_version = ?, mac_address = COALESCE(?, mac_address), office_id = COALESCE(?, office_id),
             agent_uninstalled_at = NULL, last_checkin_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $payload['computerName'] ?? null,
            $payload['computerName'] ?? null,
            $payload['os']['name'] ?? null,
            $payload['os']['version'] ?? null,
            $payload['os']['buildNumber'] ?? null,
            $payload['manufacturer'] ?? null,
            $payload['model'] ?? null,
            $payload['serialNumber'] ?? null,
            $payload['domainWorkgroup'] ?? null,
            $hardware['cpuName'] ?? null,
            $hardware['ramBytes'] ?? null,
            $diskSummary,
            $hardware['motherboard'] ?? null,
            $this->nullableBatteryHealth($hardware['batteryHealthPercent'] ?? null),
            $payload['agentVersion'] ?? null,
            $primaryMacAddress,
            $payload['officeId'] ?? ($payload['office']['officeId'] ?? null),
            $id,
        ]);
    }

    private function nullableBatteryHealth(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int)round((float)$value)));
    }

    private function recordMaintenanceChanges(int $computerId, array $payload): void
    {
        $stmt = $this->db->prepare(
            'SELECT computers.computer_name, computers.ram_bytes, computers.disk_summary, computers.os_name, computers.os_version, computers.build_number,
                    computers.battery_health_percent,
                    bios_information.version AS bios_version
             FROM computers
             LEFT JOIN bios_information ON bios_information.computer_id = computers.id
             WHERE computers.id = ?'
        );
        $stmt->execute([$computerId]);
        $current = $stmt->fetch();
        if (!$current) {
            return;
        }

        $hardware = $payload['hardware'] ?? [];
        $trustedDisks = $this->trustedDiskList($hardware['disks'] ?? []);
        $os = $payload['os'] ?? [];
        $bios = $payload['bios'] ?? [];
        $changes = [
            ['ram_size', 'RAM Size', $this->formatGb($current['ram_bytes'] ?? null), $this->formatGb($hardware['ramBytes'] ?? null)],
            ['hard_drive_size', 'Hard Drive Size', $this->formatDiskSummary($current['disk_summary'] ?? null), $trustedDisks === null ? null : $this->formatDiskList($trustedDisks)],
            ['bios_version', 'BIOS Version', $this->nullableString($current['bios_version'] ?? null), $this->nullableString($bios['version'] ?? null)],
            ['os_version', 'OS Version', $this->formatOsVersion($current['os_name'] ?? null, $current['os_version'] ?? null, $current['build_number'] ?? null), $this->formatOsVersion($os['name'] ?? null, $os['version'] ?? null, $os['buildNumber'] ?? null)],
            ['device_name', 'Device Name', $this->nullableString($current['computer_name'] ?? null), $this->nullableString($payload['computerName'] ?? null)],
            ['battery_health', 'Battery Health', $this->formatBatteryHealth($current['battery_health_percent'] ?? null), $this->formatBatteryHealth($hardware['batteryHealthPercent'] ?? null)],
        ];

        $latest = $this->db->prepare(
            'SELECT previous_value, current_value
             FROM device_maintenance_history
             WHERE computer_id = ? AND field_name = ?
             ORDER BY changed_at DESC, id DESC
             LIMIT 1'
        );
        $insert = $this->db->prepare(
            'INSERT INTO device_maintenance_history (computer_id, field_name, display_name, previous_value, current_value, changed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $changedAt = $this->agentObservedAt();
        foreach ($changes as [$fieldName, $displayName, $previousValue, $currentValue]) {
            if ($previousValue === null || $currentValue === null || $previousValue === $currentValue) {
                continue;
            }
            if ($fieldName === 'hard_drive_size'
                && $this->isLegacyDiskSummary($current['disk_summary'] ?? null)
                && $trustedDisks !== null) {
                continue;
            }
            $latest->execute([$computerId, $fieldName]);
            $latestChange = $latest->fetch();
            if ($latestChange
                && (string)($latestChange['previous_value'] ?? '') === (string)$previousValue
                && (string)($latestChange['current_value'] ?? '') === (string)$currentValue) {
                continue;
            }
            $insert->execute([$computerId, $fieldName, $displayName, $previousValue, $currentValue, $changedAt]);
            $this->completeAssignmentsForMaintenance($computerId, $fieldName, (int)$this->db->lastInsertId());
        }
    }

    private function trustedDiskSummary(array $disks): ?string
    {
        $trustedDisks = $this->trustedDiskList($disks);
        return $trustedDisks === null ? null : json_encode($trustedDisks, JSON_UNESCAPED_SLASHES);
    }

    private function trustedDiskList(array $disks): ?array
    {
        if (!$disks) {
            return null;
        }

        $trustedDisks = [];
        foreach ($disks as $disk) {
            if (!is_array($disk) || ($disk['source'] ?? null) !== 'internal') {
                return null;
            }

            $busType = strtolower(trim((string)($disk['busType'] ?? '')));
            if (in_array($busType, ['usb', 'sd', 'mmc', 'virtual', 'file backed virtual'], true)) {
                continue;
            }

            $trustedDisks[] = $disk;
        }

        return $trustedDisks ?: null;
    }

    private function isLegacyDiskSummary(mixed $diskSummary): bool
    {
        if (!$diskSummary) {
            return false;
        }

        $disks = json_decode((string)$diskSummary, true);
        if (!is_array($disks) || !$disks) {
            return false;
        }

        foreach ($disks as $disk) {
            if (is_array($disk) && array_key_exists('source', $disk)) {
                return false;
            }
        }

        return true;
    }

    private function formatBatteryHealth(mixed $value): ?string
    {
        $value = $this->nullableBatteryHealth($value);
        return $value === null ? null : $value . '%';
    }

    private function formatGb(mixed $bytes): ?string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes) || (float)$bytes <= 0) {
            return null;
        }

        $gb = (float)$bytes / 1073741824;
        $value = $gb >= 10 ? round($gb) : round($gb, 1);
        return number_format($value, $value == floor($value) ? 0 : 1) . ' GB';
    }

    private function formatDiskSummary(mixed $diskSummary): ?string
    {
        if (!$diskSummary) {
            return null;
        }

        $disks = json_decode((string)$diskSummary, true);
        return is_array($disks) ? $this->formatDiskList($disks) : null;
    }

    private function formatDiskList(array $disks): ?string
    {
        $totalBytes = 0;
        foreach ($disks as $disk) {
            $totalBytes += (int)($disk['totalBytes'] ?? 0);
        }

        return $this->formatGb($totalBytes);
    }

    private function formatOsVersion(mixed $name, mixed $version, mixed $build): ?string
    {
        $parts = array_filter([
            $this->nullableString($name),
            $this->nullableString($version) ? 'Version ' . $this->nullableString($version) : null,
            $this->nullableString($build) ? 'OS Build ' . $this->nullableString($build) : null,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    private function primaryMacAddress(array $networks): ?string
    {
        foreach ($networks as $network) {
            if (($network['isPrimary'] ?? false) !== true || ($network['adapterType'] ?? 'Other') === 'Virtual') {
                continue;
            }

            $macAddress = $this->validMacAddress($network['macAddress'] ?? null);
            if ($macAddress !== null) {
                return $macAddress;
            }
        }

        foreach (['LAN', 'WiFi', 'Other'] as $adapterType) {
            foreach ($networks as $network) {
                if (($network['adapterType'] ?? 'Other') !== $adapterType) {
                    continue;
                }

                $macAddress = $this->validMacAddress($network['macAddress'] ?? null);
                if ($macAddress !== null) {
                    return $macAddress;
                }
            }
        }

        foreach ($networks as $network) {
            $macAddress = $this->validMacAddress($network['macAddress'] ?? null);
            if ($macAddress !== null) {
                return $macAddress;
            }
        }

        return null;
    }

    private function validMacAddress(mixed $value): ?string
    {
        $compact = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', trim((string)$value)) ?? '');
        if (strlen($compact) !== 12 || $compact === '000000000000') {
            return null;
        }

        return implode(':', str_split($compact, 2));
    }

    private function replaceBios(int $computerId, array $bios): void
    {
        $this->db->prepare('DELETE FROM bios_information WHERE computer_id = ?')->execute([$computerId]);
        $stmt = $this->db->prepare(
            'INSERT INTO bios_information (computer_id, manufacturer, version, release_date, update_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $computerId,
            $bios['manufacturer'] ?? null,
            $bios['version'] ?? null,
            $bios['releaseDate'] ?? null,
            $bios['updateDate'] ?? null,
        ]);
    }

    private function replaceNetwork(int $computerId, array $networks): void
    {
        $this->db->prepare('DELETE FROM network_information WHERE computer_id = ?')->execute([$computerId]);
        $stmt = $this->db->prepare(
            'INSERT INTO network_information (computer_id, adapter_name, adapter_type, mac_address, ip_addresses, hostname, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        foreach ($networks as $network) {
            $stmt->execute([
                $computerId,
                $network['adapterName'] ?? null,
                $network['adapterType'] ?? null,
                $network['macAddress'] ?? null,
                json_encode($network['ipAddresses'] ?? [], JSON_UNESCAPED_SLASHES),
                $network['hostname'] ?? null,
            ]);
        }
    }

    private function replaceSoftware(int $computerId, array $software): void
    {
        $old = $this->db->prepare('SELECT id, name, version, publisher, installed_date, last_updated_date FROM installed_software WHERE computer_id = ?');
        $old->execute([$computerId]);
        $previous = [];
        $duplicatePrevious = [];
        foreach ($old->fetchAll() as $row) {
            $key = $this->softwareInventoryKey($row['name'] ?? '', $row['version'] ?? null);
            if (!isset($previous[$key])) {
                $previous[$key] = $row;
                continue;
            }

            $preferred = $this->preferredSoftwareRow($previous[$key], $row);
            $duplicatePrevious[] = ((int)($preferred['id'] ?? 0) === (int)($row['id'] ?? 0)) ? $previous[$key] : $row;
            $previous[$key] = $this->mergeSoftwareRows($previous[$key], $row);
        }

        $reportedSoftware = [];
        foreach ($software as $item) {
            $name = trim((string)($item['name'] ?? ''));
            if (!$this->isReportableSoftwareName($name)) {
                continue;
            }

            $normalized = [
                'name' => $name,
                'version' => $this->nullableString($item['version'] ?? null),
                'publisher' => $this->nullableString($item['publisher'] ?? null),
                'installed_date' => $this->nullableDate($item['installedDate'] ?? null),
                'last_updated_date' => $this->nullableDate($item['lastUpdatedDate'] ?? null),
            ];
            $key = $this->softwareInventoryKey($normalized['name'], $normalized['version']);
            $reportedSoftware[$key] = isset($reportedSoftware[$key])
                ? $this->mergeSoftwareRows($reportedSoftware[$key], $normalized)
                : $normalized;
        }

        $insert = $this->db->prepare(
            'INSERT INTO installed_software (computer_id, name, version, publisher, installed_date, last_updated_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $update = $this->db->prepare(
            'UPDATE installed_software
             SET name = ?, version = ?, publisher = ?, installed_date = ?, last_updated_date = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $history = $this->db->prepare(
            'INSERT INTO software_history (computer_id, software_name, previous_version, current_version, change_type, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );

        foreach ($reportedSoftware as $key => $item) {
            $name = $item['name'];
            $version = $item['version'];
            $publisher = $item['publisher'];
            $reportedInstallDate = $item['installed_date'];
            $reportedUpdatedDate = $item['last_updated_date'];

            if (!array_key_exists($key, $previous)) {
                $installedDate = $reportedInstallDate;
                $updatedDate = $reportedUpdatedDate;
                $insert->execute([
                    $computerId,
                    $name,
                    $version,
                    $publisher,
                    $installedDate,
                    $updatedDate,
                ]);
                $history->execute([$computerId, $name, null, $version, 'installed']);
                $this->completeAssignmentsForSoftware($computerId, $name, (int)$this->db->lastInsertId());
                continue;
            }

            $existing = $previous[$key];
            $previousVersion = $this->nullableString($existing['version'] ?? null);
            $versionChanged = $previousVersion !== $version;
            $installedDate = $existing['installed_date'] ?: $reportedInstallDate;
            $updatedDate = $reportedUpdatedDate ?: ($existing['last_updated_date'] ?: null);
            if ($versionChanged) {
                $updatedDate = $reportedUpdatedDate ?? $reportedInstallDate ?? date('Y-m-d');
            } elseif ($updatedDate !== null && $updatedDate === $installedDate && $reportedUpdatedDate === null) {
                $updatedDate = null;
            }

            $update->execute([
                $name,
                $version,
                $publisher,
                $installedDate,
                $updatedDate,
                $existing['id'],
            ]);

            if ($versionChanged) {
                $history->execute([$computerId, $name, $previousVersion, $version, 'updated']);
                $this->completeAssignmentsForSoftware($computerId, $name, (int)$this->db->lastInsertId());
            }
            unset($previous[$key]);
        }

        $delete = $this->db->prepare('DELETE FROM installed_software WHERE id = ?');
        foreach ($duplicatePrevious as $row) {
            $delete->execute([$row['id']]);
        }
        foreach ($previous as $row) {
            $delete->execute([$row['id']]);
            $history->execute([$computerId, $row['name'], $row['version'], null, 'removed']);
        }
    }

    private function replaceDrivers(int $computerId, array $drivers): void
    {
        $old = $this->db->prepare(
            'SELECT device_name, version, inf_name, device_class
             FROM installed_drivers
             WHERE computer_id = ?'
        );
        $old->execute([$computerId]);
        $previous = [];
        foreach ($old->fetchAll() as $row) {
            $previous[$this->driverInventoryKey($row)] = $row;
        }

        $this->db->prepare('DELETE FROM installed_drivers WHERE computer_id = ?')->execute([$computerId]);
        $stmt = $this->db->prepare(
            'INSERT INTO installed_drivers
                (computer_id, device_name, version, provider, manufacturer, driver_date, installed_date, last_updated_date, inf_name, device_class, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $history = $this->db->prepare(
            'INSERT INTO driver_history (computer_id, device_name, previous_version, current_version, change_type, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );

        foreach ($drivers as $item) {
            if (!is_array($item)) {
                continue;
            }
            $deviceName = $this->boundedString($item['deviceName'] ?? null, 255);
            if ($deviceName === '') {
                continue;
            }
            $version = $this->nullableBoundedString($item['version'] ?? null, 120);
            $infName = $this->nullableBoundedString($item['infName'] ?? null, 190);
            $deviceClass = $this->nullableBoundedString($item['deviceClass'] ?? null, 120);
            $key = $this->driverInventoryKey([
                'device_name' => $deviceName,
                'inf_name' => $infName,
                'device_class' => $deviceClass,
            ]);
            $stmt->execute([
                $computerId,
                $deviceName,
                $version,
                $this->nullableBoundedString($item['provider'] ?? null, 190),
                $this->nullableBoundedString($item['manufacturer'] ?? null, 190),
                $this->nullableDate($item['driverDate'] ?? null),
                $this->nullableDate($item['installedDate'] ?? null),
                $this->nullableDate($item['lastUpdatedDate'] ?? null),
                $infName,
                $deviceClass,
            ]);

            if (!array_key_exists($key, $previous)) {
                $history->execute([$computerId, $deviceName, null, $version, 'installed']);
                continue;
            }

            $existing = $previous[$key];
            $previousVersion = $this->nullableString($existing['version'] ?? null);
            if ($previousVersion !== $version) {
                $history->execute([$computerId, $deviceName, $previousVersion, $version, 'updated']);
            }
            unset($previous[$key]);
        }

        foreach ($previous as $row) {
            $history->execute([$computerId, $row['device_name'], $row['version'], null, 'removed']);
        }
    }

    private function driverInventoryKey(array $driver): string
    {
        return strtolower(implode('|', [
            trim((string)($driver['device_name'] ?? '')),
            trim((string)($driver['inf_name'] ?? '')),
            trim((string)($driver['device_class'] ?? '')),
        ]));
    }

    private function softwareInventoryKey(mixed $name, mixed $version = null): string
    {
        $key = strtolower(trim((string)$name));
        $key = preg_replace('/[\x{00ae}\x{2122}]/u', '', $key) ?? $key;
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;

        $version = trim((string)$version);
        if ($version !== '') {
            $quoted = preg_quote($version, '/');
            $key = preg_replace('/(?<![a-z0-9])' . $quoted . '(?![a-z0-9])/i', ' ', $key) ?? $key;
            $key = preg_replace('/\b(?:version|ver\.?|v)\s*$/i', ' ', $key) ?? $key;
        }

        return trim(preg_replace('/\s+/', ' ', $key) ?? $key);
    }

    private function softwareTaskKey(mixed $name): string
    {
        $key = strtolower(trim((string)$name));
        $key = preg_replace('/[\x{00ae}\x{2122}]/u', '', $key) ?? $key;
        $key = preg_replace('/\b(?:version|ver\.?|v)\s*\d+(?:\.\d+)+(?:[-+._a-z0-9]*)?/i', ' ', $key) ?? $key;
        $key = preg_replace('/(?<![a-z0-9])\d+(?:\.\d+)+(?:[-+._a-z0-9]*)?(?![a-z0-9])/i', ' ', $key) ?? $key;
        return trim(preg_replace('/\s+/', ' ', $key) ?? $key);
    }

    private function mergeSoftwareRows(array $existing, array $item): array
    {
        $primary = $this->preferredSoftwareRow($existing, $item);
        $fallback = $primary === $item ? $existing : $item;

        return [
            'id' => $primary['id'] ?? $fallback['id'] ?? null,
            'name' => $primary['name'] ?? $fallback['name'] ?? null,
            'version' => $primary['version'] ?? $fallback['version'] ?? null,
            'publisher' => $primary['publisher'] ?? $fallback['publisher'] ?? null,
            'installed_date' => $primary['installed_date'] ?? $fallback['installed_date'] ?? null,
            'last_updated_date' => $primary['last_updated_date'] ?? $fallback['last_updated_date'] ?? null,
        ];
    }

    private function preferredSoftwareRow(array $existing, array $item): array
    {
        $existingDate = max(
            strtotime((string)($existing['last_updated_date'] ?? '')) ?: 0,
            strtotime((string)($existing['installed_date'] ?? '')) ?: 0
        );
        $itemDate = max(
            strtotime((string)($item['last_updated_date'] ?? '')) ?: 0,
            strtotime((string)($item['installed_date'] ?? '')) ?: 0
        );
        if ($existingDate !== $itemDate) {
            return $itemDate > $existingDate ? $item : $existing;
        }

        $existingVersion = $this->nullableString($existing['version'] ?? null);
        $itemVersion = $this->nullableString($item['version'] ?? null);
        if ($existingVersion !== null && $itemVersion !== null && $existingVersion !== $itemVersion) {
            return version_compare($itemVersion, $existingVersion, '>') ? $item : $existing;
        }

        return $this->softwareCompletenessScore($item) >= $this->softwareCompletenessScore($existing) ? $item : $existing;
    }

    private function softwareCompletenessScore(array $item): int
    {
        $score = 0;
        foreach (['version', 'publisher', 'installed_date', 'last_updated_date'] as $field) {
            if ($this->nullableString($item[$field] ?? null) !== null) {
                $score++;
            }
        }

        return $score;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function nullableBoundedString(mixed $value, int $length): ?string
    {
        return $this->nullableString($this->boundedString($value, $length));
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function isReportableSoftwareName(string $name): bool
    {
        if ($name === '' || preg_match('/^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$/i', $name)) {
            return false;
        }

        return !preg_match('/^(Microsoft(?:\.Windows| Windows[A-Z.])|MicrosoftWindows[ .]|Windows[ .]|WindowsApps[ .]|WindowsSubsystem[ .]|cr[ .]sb[ .])/i', $name);
    }
}
