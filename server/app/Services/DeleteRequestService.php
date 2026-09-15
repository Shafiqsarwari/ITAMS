<?php

class DeleteRequestService
{
    private const ENTITY_PERMISSIONS = [
        'device' => 'assets.delete',
        'employee' => 'employees.delete',
        'office' => 'offices.delete',
    ];

    private static bool $schemaEnsured = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        UserPermissionService::ensureSchema();
        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS delete_requests (
                id BIGINT NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(30) NOT NULL,
                entity_ids TEXT NOT NULL,
                entity_labels TEXT NULL,
                permission_slug VARCHAR(120) NOT NULL,
                requested_by_user_id INT NOT NULL,
                assigned_to_user_id INT NOT NULL,
                request_note TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "pending",
                decision_note TEXT NULL,
                decided_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL,
                PRIMARY KEY (id),
                KEY idx_delete_requests_assignee (assigned_to_user_id, status),
                KEY idx_delete_requests_requester (requested_by_user_id, status),
                CONSTRAINT fk_delete_requests_requester FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_delete_requests_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        self::$schemaEnsured = true;
    }

    public static function tableExists(): bool
    {
        static $exists;
        if ($exists !== null) {
            return $exists;
        }
        $exists = (bool)Database::connection()->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'delete_requests'"
        )->fetchColumn();
        return $exists;
    }

    public static function permissionFor(string $entityType): ?string
    {
        return self::ENTITY_PERMISSIONS[$entityType] ?? null;
    }

    public static function approvers(string $permission): array
    {
        if (!in_array($permission, self::ENTITY_PERMISSIONS, true)) {
            return [];
        }

        self::ensureSchema();
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT users.id, users.name, users.email, users.office_id, roles.slug AS role_slug,
                    offices.name AS office_name
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN offices ON offices.id = users.office_id
             LEFT JOIN user_permissions ON user_permissions.user_id = users.id
             LEFT JOIN permissions ON permissions.id = user_permissions.permission_id
             WHERE users.status = 'active'
               AND (
                    roles.slug = 'super-admin'
                    OR (roles.slug = 'admin' AND users.office_id IS NULL)
                    OR permissions.slug = ?
               )
             ORDER BY users.name, users.email"
        );
        $stmt->execute([$permission]);
        return $stmt->fetchAll();
    }

    public static function create(array $requester, string $entityType, array $entityIds, int $assigneeId, ?string $note = null): int
    {
        self::ensureSchema();
        $permission = self::permissionFor($entityType);
        $ids = self::normalizeIds($entityIds);
        if (!$permission || !$ids) {
            throw new InvalidArgumentException('Select at least one valid item.');
        }

        $basePermission = match ($entityType) {
            'device' => 'assets.view',
            'employee' => 'employees.manage',
            'office' => 'offices.manage',
        };
        if (!Auth::can($basePermission)) {
            throw new RuntimeException('You do not have access to request this action.');
        }

        $labels = self::validateAndLabels($requester, $entityType, $ids, false);
        $assignee = self::userById($assigneeId);
        if (!$assignee || !self::userHasPermission($assignee, $permission)) {
            throw new InvalidArgumentException('Select a user authorized to approve this delete action.');
        }
        self::validateAndLabels($assignee, $entityType, $ids, true);

        $db = Database::connection();
        $idsJson = json_encode($ids, JSON_THROW_ON_ERROR);
        $duplicate = $db->prepare(
            "SELECT id FROM delete_requests
             WHERE entity_type = ? AND entity_ids = ? AND status = 'pending'
             LIMIT 1"
        );
        $duplicate->execute([$entityType, $idsJson]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('A pending delete request already exists for the selected item.');
        }

        $stmt = $db->prepare(
            'INSERT INTO delete_requests
             (entity_type, entity_ids, entity_labels, permission_slug, requested_by_user_id, assigned_to_user_id, request_note, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NOW(), NOW())'
        );
        $stmt->execute([
            $entityType,
            $idsJson,
            json_encode($labels, JSON_THROW_ON_ERROR),
            $permission,
            (int)$requester['id'],
            $assigneeId,
            trim((string)$note) !== '' ? trim((string)$note) : null,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function requestsForUser(array $user): array
    {
        if (!self::tableExists()) {
            return [];
        }

        $params = [];
        if (ScopeService::isAdmin($user)) {
            $where = '1=1';
        } else {
            $where = '(delete_requests.requested_by_user_id = ? OR delete_requests.assigned_to_user_id = ?)';
            $params = [(int)$user['id'], (int)$user['id']];
        }
        $stmt = Database::connection()->prepare(
            'SELECT delete_requests.*, requester.name AS requested_by_name, assignee.name AS assigned_to_name
             FROM delete_requests
             JOIN users requester ON requester.id = delete_requests.requested_by_user_id
             JOIN users assignee ON assignee.id = delete_requests.assigned_to_user_id
             WHERE ' . $where . '
             ORDER BY CASE WHEN delete_requests.status = "pending" THEN 0 ELSE 1 END,
                      delete_requests.created_at DESC, delete_requests.id DESC
             LIMIT 100'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function decide(array $actor, int $requestId, string $decision, ?string $note = null): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Select a valid decision.');
        }

        self::ensureSchema();
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM delete_requests WHERE id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new RuntimeException('Pending delete request was not found.');
            }
            if ((int)$request['assigned_to_user_id'] !== (int)$actor['id']) {
                throw new RuntimeException('Only the assigned approver can decide this request.');
            }
            if (!self::userHasPermission($actor, (string)$request['permission_slug'])) {
                throw new RuntimeException('You no longer have permission to approve this action.');
            }

            $ids = self::normalizeIds(json_decode((string)$request['entity_ids'], true) ?: []);
            if ($decision === 'approved') {
                self::validateAndLabels($actor, (string)$request['entity_type'], $ids, true);
                self::performDelete($actor, (string)$request['entity_type'], $ids);
            }

            $update = $db->prepare(
                'UPDATE delete_requests SET status = ?, decision_note = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?'
            );
            $update->execute([$decision, trim((string)$note) !== '' ? trim((string)$note) : null, $requestId]);
            Audit::log($decision . '_delete_request', 'delete_request', $requestId, [
                'entity_type' => $request['entity_type'],
                'entity_ids' => $ids,
            ]);
            $db->commit();
            return $request;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    public static function pendingSummary(int $userId): array
    {
        if ($userId <= 0 || !self::tableExists()) {
            return ['pending_count' => 0, 'latest_id' => 0];
        }
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) AS pending_count, COALESCE(MAX(id), 0) AS latest_id
             FROM delete_requests WHERE assigned_to_user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        return ['pending_count' => (int)($row['pending_count'] ?? 0), 'latest_id' => (int)($row['latest_id'] ?? 0)];
    }

    private static function validateAndLabels(array $user, string $entityType, array $ids, bool $approving): array
    {
        $db = Database::connection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($entityType === 'device') {
            NetworkPrinterService::schema($db);
            $stmt = $db->prepare("SELECT id, office_id, COALESCE(NULLIF(device_name, ''), computer_name) AS label FROM computers WHERE id IN ({$placeholders})");
        } elseif ($entityType === 'employee') {
            $stmt = $db->prepare("SELECT id, office_id, name AS label FROM employees WHERE id IN ({$placeholders})");
        } elseif ($entityType === 'office') {
            $stmt = $db->prepare("SELECT id, id AS office_id, name AS label FROM offices WHERE id IN ({$placeholders})");
        } else {
            throw new InvalidArgumentException('Invalid delete request type.');
        }
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($ids)) {
            throw new RuntimeException('One or more selected items no longer exist.');
        }

        foreach ($rows as $row) {
            $officeId = (int)($row['office_id'] ?? 0);
            $accessible = $officeId > 0 && ScopeService::canManageOffice($user, $officeId);
            if ($entityType === 'device' && $officeId <= 0) {
                $accessible = ScopeService::isAdmin($user)
                    || (!$approving && Auth::can('offices.manage'))
                    || NetworkPrinterService::canAccessUnassigned($db, (int)$row['id'], $user);
            }
            if (!$accessible) {
                throw new RuntimeException('One or more selected items are outside your office access.');
            }
        }

        if ($entityType === 'office' && $approving) {
            $userCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE office_id IN ({$placeholders})");
            $userCheck->execute($ids);
            if ((int)$userCheck->fetchColumn() > 0) {
                throw new RuntimeException('Reassign users from the selected office before approving its deletion.');
            }
        }

        return array_values(array_map(static fn(array $row): string => (string)($row['label'] ?: ('Item #' . $row['id'])), $rows));
    }

    private static function performDelete(array $actor, string $entityType, array $ids): void
    {
        $db = Database::connection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($entityType === 'device') {
            $stmt = $db->prepare("DELETE FROM computers WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        } elseif ($entityType === 'employee') {
            $stmt = $db->prepare("DELETE FROM employees WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        } elseif ($entityType === 'office') {
            $unassign = $db->prepare("UPDATE computers SET office_id = NULL, assigned_employee_id = NULL, updated_at = NOW() WHERE office_id IN ({$placeholders})");
            $unassign->execute($ids);
            $children = $db->prepare("UPDATE offices SET parent_id = NULL, updated_at = NOW() WHERE parent_id IN ({$placeholders})");
            $children->execute($ids);
            $stmt = $db->prepare("DELETE FROM offices WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }

        foreach ($ids as $id) {
            Audit::log('deleted', $entityType, $id, ['approved_by_user_id' => (int)$actor['id']]);
        }
    }

    private static function userById(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT users.*, roles.slug AS role_slug FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.status = 'active'"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    private static function userHasPermission(array $user, string $permission): bool
    {
        return ($user['role_slug'] ?? '') === 'super-admin'
            || ScopeService::isAdmin($user)
            || UserPermissionService::hasPermission((int)$user['id'], $permission);
    }

    private static function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
