<?php

// Keeps collector inventory separate from the user's office/employee assignment.
class NetworkPrinterService
{
    public static function schema(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS network_collectors (computer_id BIGINT PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $db->exec('CREATE TABLE IF NOT EXISTS network_devices (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, collector_id BIGINT NOT NULL, address VARCHAR(45) NOT NULL,
            details JSON NULL, reachable TINYINT NOT NULL DEFAULT 0, checked_at DATETIME NOT NULL, last_seen_at DATETIME NULL,
            UNIQUE KEY collector_address (collector_id, address)
        ) ENGINE=InnoDB');
        $db->exec('CREATE TABLE IF NOT EXISTS network_printer_links (
            computer_id BIGINT PRIMARY KEY, collector_id BIGINT NOT NULL,
            address VARCHAR(45) NOT NULL, requested_by_user_id INT NULL,
            state VARCHAR(20) NOT NULL DEFAULT "queued", reachable TINYINT NOT NULL DEFAULT 0,
            checked_at DATETIME NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY collector_address (collector_id, address),
            FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
    }

    public static function privateIpv4(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $b = array_map('intval', explode('.', $address));
        return $b[0] === 10 || ($b[0] === 192 && $b[1] === 168) || ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31);
    }

    public static function collectors(PDO $db, array $user): array
    {
        $offlineAfter = max(1, (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes']);
        [$where, $params] = ScopeService::assetWhere($user, 'c');
        $stmt = $db->prepare('SELECT c.id, c.computer_name, c.agent_version, o.name AS office_name
            FROM computers c JOIN network_collectors nc ON nc.computer_id = c.id AND nc.enabled = 1
            LEFT JOIN offices o ON o.id = c.office_id
            WHERE c.agent_token_hash IS NOT NULL AND c.agent_uninstalled_at IS NULL
            AND c.last_checkin_at >= NOW() - INTERVAL ' . $offlineAfter . ' MINUTE
            AND ' . $where . ' ORDER BY c.computer_name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function onlineSql(int $minutes): string
    {
        $minutes = max(1, $minutes);
        return "(CASE WHEN EXISTS (SELECT 1 FROM network_printer_links np WHERE np.computer_id = computers.id)
            THEN EXISTS (SELECT 1 FROM network_printer_links np
                JOIN computers collector_computer ON collector_computer.id = np.collector_id
                JOIN network_collectors nc ON nc.computer_id = np.collector_id AND nc.enabled = 1
                WHERE np.computer_id = computers.id
                AND np.reachable = 1 AND np.checked_at >= NOW() - INTERVAL 30 MINUTE
                AND collector_computer.agent_uninstalled_at IS NULL
                AND collector_computer.last_checkin_at >= NOW() - INTERVAL {$minutes} MINUTE)
            ELSE (computers.agent_uninstalled_at IS NULL AND computers.last_checkin_at >= NOW() - INTERVAL {$minutes} MINUTE) END)";
    }

    public static function canAccessUnassigned(PDO $db, int $id, array $user): bool
    {
        $stmt = $db->prepare('SELECT collector_id FROM network_printer_links WHERE computer_id = ?');
        $stmt->execute([$id]);
        $collectorId = $stmt->fetchColumn();
        if (!$collectorId || ScopeService::isAdmin($user)) return true;
        [$where, $params] = ScopeService::assetWhere($user);
        $stmt = $db->prepare('SELECT id FROM computers WHERE id = ? AND ' . $where);
        $stmt->execute([(int)$collectorId, ...$params]);
        return (bool)$stmt->fetchColumn();
    }

    public static function add(PDO $db, int $collectorId, string $address, array $user): int
    {
        if (!self::privateIpv4($address)) throw new InvalidArgumentException('Enter a private IPv4 address, for example 192.168.137.26.');
        $allowed = array_map('intval', array_column(self::collectors($db, $user), 'id'));
        if (!in_array($collectorId, $allowed, true)) throw new InvalidArgumentException('Select an enabled collector available to your office.');
        $db->beginTransaction();
        try {
            // Serialize additions for one collector; concurrent submits cannot duplicate a printer.
            $lock = $db->prepare('SELECT computer_id FROM network_collectors WHERE computer_id = ? AND enabled = 1 FOR UPDATE');
            $lock->execute([$collectorId]);
            if (!$lock->fetchColumn()) throw new InvalidArgumentException('Collector is no longer enabled.');
            $stmt = $db->prepare('SELECT computer_id FROM network_printer_links WHERE collector_id = ? AND address = ?');
            $stmt->execute([$collectorId, $address]);
            if ($existing = $stmt->fetchColumn()) { $db->commit(); return (int)$existing; }
            $stmt = $db->prepare('SELECT COUNT(*) FROM network_printer_links WHERE collector_id = ?');
            $stmt->execute([$collectorId]);
            if ((int)$stmt->fetchColumn() >= 64) throw new InvalidArgumentException('This collector already has 64 managed network devices.');
            $stmt = $db->prepare('INSERT INTO computers (computer_name,device_name,device_type,is_offline_device,added_by_user_id,created_at,updated_at)
                VALUES (?, ?, "Network Device", 0, ?, NOW(), NOW())');
            $stmt->execute(['SNMP-' . $collectorId . '-' . str_replace('.', '-', $address), 'Network device ' . $address, (int)$user['id']]);
            $id = (int)$db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO network_printer_links (computer_id,collector_id,address,requested_by_user_id,created_at) VALUES (?,?,?,?,NOW())');
            $stmt->execute([$id, $collectorId, $address, (int)$user['id']]);
            // Reuse an already discovered printer without creating a second network record.
            $stmt = $db->prepare('SELECT * FROM network_devices WHERE collector_id = ? AND address = ?');
            $stmt->execute([$collectorId, $address]);
            if ($cached = $stmt->fetch()) {
                $details = json_decode($cached['details'] ?? '{}', true) ?: [];
                self::applyReport($db, $collectorId, $address, (bool)$cached['reachable'], $details, $cached['checked_at']);
            }
            $db->commit();
            return $id;
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function applyReport(PDO $db, int $collectorId, string $address, bool $reachable, array $details, ?string $checkedAt = null): void
    {
        $stmt = $db->prepare('SELECT computer_id FROM network_printer_links WHERE collector_id = ? AND address = ?');
        $stmt->execute([$collectorId, $address]);
        $id = $stmt->fetchColumn();
        if (!$id) return;
        $stmt = $db->prepare('UPDATE network_printer_links SET reachable = ?, state = ?, checked_at = COALESCE(?,NOW()) WHERE computer_id = ?');
        $stmt->execute([(int)$reachable, $reachable ? 'ready' : 'unavailable', $checkedAt, $id]);
        if (!$reachable) return; // Keep last known model/firmware when printer is unreachable.
        $bounded = static fn($value) => mb_substr(trim((string)$value), 0, 150);
        $model = $bounded($details['model'] ?? '');
        $kind = strtolower(trim((string)($details['kind'] ?? 'network')));
        $deviceType = match ($kind) {
            'printer' => 'Printer',
            'switch' => 'Switch',
            'ap', 'access point' => 'Access Point',
            default => 'Network Device',
        };
        $stmt = $db->prepare('UPDATE computers SET
            device_name = CASE WHEN device_name IN (?, ?) THEN ? ELSE device_name END,
            device_type = ?,
            model = COALESCE(NULLIF(?,""),model), serial_number = COALESCE(NULLIF(?,""),serial_number),
            firmware_version = COALESCE(NULLIF(?,""),firmware_version),
            last_checkin_at = COALESCE(?,NOW()), updated_at = NOW() WHERE id = ?');
        $stmt->execute(['Network printer ' . $address, 'Network device ' . $address,
            $bounded($details['name'] ?? '') ?: ($model ?: 'Network device ' . $address), $deviceType,
            $model, $bounded($details['serial'] ?? ''), $bounded($details['firmware'] ?? ''), $checkedAt, $id]);
    }
}
