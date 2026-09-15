<?php

class AssignmentController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->ensureUpdateAssignmentsTable();
        AssignmentAlertService::markSeenForUser((int)$user['id']);
        $canReviewAllAssignments = $this->canManageAssignments($user);
        $managedOfficeIds = $this->managedOfficeIds($user);
        $userId = (int)$user['id'];
        if ($canReviewAllAssignments && !ScopeService::isAdmin($user)) {
            $assignmentWhere = 'update_assignments.status = ? AND (
                computers.office_id IN (' . implode(',', array_fill(0, count($managedOfficeIds), '?')) . ')
                OR update_assignments.assigned_by_user_id = ?
                OR update_assignments.assigned_to_user_id = ?
            )';
            $pendingParams = array_merge(['pending'], $managedOfficeIds, [$userId, $userId]);
        } else {
            $assignmentWhere = $canReviewAllAssignments
                ? 'update_assignments.status = ?'
                : 'update_assignments.status = ? AND (update_assignments.assigned_to_user_id = ? OR update_assignments.assigned_by_user_id = ?)';
            $pendingParams = $canReviewAllAssignments ? ['pending'] : ['pending', $userId, $userId];
        }
        $completedWhere = 'update_assignments.status = ? AND (update_assignments.assigned_by_user_id = ? OR update_assignments.assigned_to_user_id = ?)';
        $completedParams = ['completed', $userId, $userId];

        $pendingUpdateAssignments = $this->assignments($assignmentWhere, $pendingParams, 'update_assignments.created_at DESC');
        $completedUpdateAssignments = $this->assignments($completedWhere, $completedParams, 'update_assignments.completed_at DESC');
        $deleteRequests = DeleteRequestService::requestsForUser($user);
        $assignmentOffices = [];
        $assignmentDevices = [];
        $assignmentUsers = [];
        $assignmentSoftware = [];
        if ($canReviewAllAssignments) {
            $assignmentOffices = $this->officeOptionsForAssignment($user, $managedOfficeIds);
            $officeFilter = ScopeService::isAdmin($user) ? '1=1' : 'computers.office_id IN (' . implode(',', array_fill(0, count($managedOfficeIds), '?')) . ')';
            $deviceStmt = $this->db->prepare(
                'SELECT computers.id, computers.office_id,
                    COALESCE(NULLIF(computers.device_name, ""), computers.computer_name) AS label,
                    computers.computer_name,
                    computers.serial_number,
                    offices.name AS office_name,
                    employees.name AS assigned_employee_name
                 FROM computers
                 LEFT JOIN offices ON offices.id = computers.office_id
                 LEFT JOIN employees ON employees.id = computers.assigned_employee_id
                 WHERE ' . $officeFilter . '
                 ORDER BY computers.device_name, computers.computer_name'
            );
            $deviceStmt->execute(ScopeService::isAdmin($user) ? [] : $managedOfficeIds);
            $assignmentDevices = $deviceStmt->fetchAll();

            $userFilter = ScopeService::isAdmin($user)
                ? '1=1'
                : "(users.office_id IN (" . implode(',', array_fill(0, count($managedOfficeIds), '?')) . ") OR (roles.slug = 'admin' AND users.office_id IS NULL))";
            $userStmt = $this->db->prepare(
                'SELECT users.id, users.name, users.email, users.office_id, roles.slug AS role_slug, offices.name AS office_name
                 FROM users
                 JOIN roles ON roles.id = users.role_id
                 LEFT JOIN offices ON offices.id = users.office_id
                 WHERE users.status = \'active\' AND roles.slug IN (\'admin\', \'user\')
                   AND ' . $userFilter . '
                 ORDER BY users.name, users.email'
            );
            $userStmt->execute(ScopeService::isAdmin($user) ? [] : $managedOfficeIds);
            $assignmentUsers = $userStmt->fetchAll();

            $softwareFilter = ScopeService::isAdmin($user) ? '1=1' : 'computers.office_id IN (' . implode(',', array_fill(0, count($managedOfficeIds), '?')) . ')';
            $softwareStmt = $this->db->prepare(
                'SELECT installed_software.computer_id, installed_software.name, installed_software.version
                 FROM installed_software
                 JOIN computers ON computers.id = installed_software.computer_id
                 WHERE ' . $softwareFilter . '
                 ORDER BY installed_software.name, installed_software.version'
            );
            $softwareStmt->execute(ScopeService::isAdmin($user) ? [] : $managedOfficeIds);
            $assignmentSoftware = $softwareStmt->fetchAll();
        }

        $this->view('assignments/index', compact('pendingUpdateAssignments', 'completedUpdateAssignments', 'deleteRequests', 'canReviewAllAssignments', 'assignmentOffices', 'assignmentDevices', 'assignmentUsers', 'assignmentSoftware') + [
            'title' => 'Tasks',
            'subtitle' => $canReviewAllAssignments ? 'Track update work and completed task notifications' : 'Your assigned update work',
        ]);
    }

    public function update(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        if (!$this->canManageAssignments($user)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $this->ensureUpdateAssignmentsTable();
        $id = (int)Request::input('id');
        $officeId = (int)Request::input('office_id');
        $deviceId = (int)Request::input('device_id');
        $assignedToUserId = (int)Request::input('assigned_to_user_id');
        $taskType = (string)Request::input('task_type');
        $note = trim((string)Request::input('note'));
        $assignment = $this->assignmentByStatus($id, 'pending');
        if (!$assignment) {
            $this->flash('danger', 'Pending task not found.');
            Response::redirect('/task');
        }
        if ((int)$assignment['assigned_to_user_id'] === (int)$user['id']) {
            $this->flash('danger', 'You cannot edit a task assigned to you.');
            Response::redirect('/task');
        }

        if (!in_array($taskType, $this->editableTaskTypes(), true) || $officeId <= 0 || $deviceId <= 0 || $assignedToUserId <= 0) {
            $this->flash('danger', 'Select a task type, office, device, and user before saving.');
            Response::redirect('/task');
        }

        $device = $this->one('SELECT id, office_id FROM computers WHERE id = ?', [$deviceId]);
        $assignee = $this->one(
            'SELECT users.id, users.name, users.office_id, roles.slug AS role_slug
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.status = \'active\' AND roles.slug IN (\'admin\', \'user\')',
            [$assignedToUserId]
        );
        if (!$device || !$assignee || (int)($device['office_id'] ?? 0) !== $officeId || !ScopeService::canManageOffice($user, $officeId) || !$this->assigneeCanReceiveOffice($assignee, $officeId)) {
            $this->flash('danger', 'The selected device or user is no longer available.');
            Response::redirect('/task');
        }

        $softwareName = trim((string)Request::input('software_name'));
        if ($taskType === 'software_update') {
            $software = $this->one('SELECT id FROM installed_software WHERE computer_id = ? AND name = ?', [$deviceId, $softwareName]);
            if (!$software) {
                $this->flash('danger', 'Select software installed on the selected device.');
                Response::redirect('/task');
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE update_assignments
             SET computer_id = ?, task_kind = ?, task_type = ?, software_name = ?, assigned_to_user_id = ?, note = ?, updated_at = NOW()
             WHERE id = ? AND status = \'pending\''
        );
        $stmt->execute([
            $deviceId,
            $taskType === 'software_update' ? 'software' : 'device',
            $taskType,
            $taskType === 'software_update' && $softwareName !== '' ? $softwareName : null,
            $assignedToUserId,
            $note === '' ? null : $note,
            $id,
        ]);

        Audit::log('updated_update_assignment', 'device', $deviceId, [
            'assignment_id' => $id,
            'task_type' => $taskType,
            'assigned_to_user_id' => $assignedToUserId,
        ]);
        $this->flash('success', 'Pending task updated.');
        Response::redirect('/task');
    }

    public function cancel(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        if (!$this->canManageAssignments($user)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $this->ensureUpdateAssignmentsTable();
        $id = (int)Request::input('id');
        $assignment = $this->assignmentByStatus($id, 'pending');
        if (!$assignment) {
            $this->flash('danger', 'Pending task not found.');
            Response::redirect('/task');
        }
        if ((int)$assignment['assigned_to_user_id'] === (int)$user['id']) {
            $this->flash('danger', 'You cannot cancel a task assigned to you.');
            Response::redirect('/task');
        }
        if (!ScopeService::canManageOffice($user, (int)($assignment['office_id'] ?? 0))) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $delete = $this->db->prepare("DELETE FROM update_assignments WHERE id = ? AND status = 'pending'");
        $delete->execute([$id]);
        Audit::log('canceled_update_assignment', 'device', (int)$assignment['computer_id'], [
            'assignment_id' => $id,
            'task_type' => $assignment['task_type'],
            'assigned_to_user_id' => (int)$assignment['assigned_to_user_id'],
        ]);
        $this->flash('success', 'Pending task canceled.');
        Response::redirect('/task');
    }

    public function deleteCompleted(): void
    {
        $user = $this->requirePermission('assets.manage');
        Csrf::verify();
        if (!$this->canManageAssignments($user)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $this->ensureUpdateAssignmentsTable();
        $ids = $this->selectedIds();
        if (!$ids) {
            $this->flash('danger', 'Completed task not found.');
            Response::redirect('/task');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT update_assignments.id, update_assignments.computer_id, update_assignments.task_type, update_assignments.assigned_to_user_id, update_assignments.assigned_by_user_id, computers.office_id
             FROM update_assignments
             JOIN computers ON computers.id = update_assignments.computer_id
             WHERE update_assignments.id IN ({$placeholders}) AND update_assignments.status = 'completed'"
        );
        $stmt->execute($ids);
        $assignments = $stmt->fetchAll();
        if (!$assignments) {
            $this->flash('danger', 'Completed task not found.');
            Response::redirect('/task');
        }
        foreach ($assignments as $assignment) {
            if ((int)($assignment['assigned_by_user_id'] ?? 0) !== (int)$user['id'] || !ScopeService::canManageOffice($user, (int)($assignment['office_id'] ?? 0))) {
                http_response_code(403);
                echo 'Forbidden';
                exit;
            }
        }

        $deleteIds = array_map(static fn(array $assignment): int => (int)$assignment['id'], $assignments);
        $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $delete = $this->db->prepare("DELETE FROM update_assignments WHERE id IN ({$deletePlaceholders}) AND status = 'completed'");
        $delete->execute($deleteIds);
        foreach ($assignments as $assignment) {
            Audit::log('deleted_completed_update_assignment', 'device', (int)$assignment['computer_id'], [
                'assignment_id' => (int)$assignment['id'],
                'task_type' => $assignment['task_type'],
                'assigned_to_user_id' => (int)$assignment['assigned_to_user_id'],
            ]);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'Completed task deleted.' : count($deleteIds) . ' completed tasks deleted.');
        Response::redirect('/task');
    }

    private function assignments(string $where, array $params, string $orderBy): array
    {
        $stmt = $this->db->prepare(
            'SELECT update_assignments.*,
                computers.device_name,
                computers.computer_name,
                computers.office_id AS office_id,
                offices.name AS office_name,
                assigned_users.name AS assigned_to_name,
                assigned_users.email AS assigned_to_email,
                assigned_by.name AS assigned_by_name,
                COALESCE(
                    NULLIF(software_evidence.software_name, \'\'),
                    NULLIF(maintenance_evidence.display_name, \'\'),
                    NULLIF(update_assignments.software_name, \'\')
                ) AS completed_item_name,
                CASE
                    WHEN update_assignments.evidence_type = \'software_history\' THEN software_evidence.previous_version
                    WHEN update_assignments.evidence_type = \'maintenance\' THEN maintenance_evidence.previous_value
                    ELSE NULL
                END AS evidence_previous_value,
                CASE
                    WHEN update_assignments.evidence_type = \'software_history\' THEN software_evidence.current_version
                    WHEN update_assignments.evidence_type = \'maintenance\' THEN maintenance_evidence.current_value
                    ELSE NULL
                END AS evidence_current_value,
                software_evidence.change_type AS software_change_type
             FROM update_assignments
             INNER JOIN computers ON computers.id = update_assignments.computer_id
             LEFT JOIN offices ON offices.id = computers.office_id
             INNER JOIN users AS assigned_users ON assigned_users.id = update_assignments.assigned_to_user_id
             LEFT JOIN users AS assigned_by ON assigned_by.id = update_assignments.assigned_by_user_id
             LEFT JOIN device_maintenance_history AS maintenance_evidence
               ON update_assignments.evidence_type = \'maintenance\'
              AND maintenance_evidence.id = update_assignments.evidence_id
             LEFT JOIN software_history AS software_evidence
               ON update_assignments.evidence_type = \'software_history\'
              AND software_evidence.id = update_assignments.evidence_id
             WHERE ' . $where . '
             ORDER BY ' . $orderBy . ', update_assignments.id DESC
             LIMIT 100'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function assignmentByStatus(int $id, string $status): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT update_assignments.id, update_assignments.computer_id, update_assignments.task_type, update_assignments.assigned_to_user_id, computers.office_id
             FROM update_assignments
             JOIN computers ON computers.id = update_assignments.computer_id
             WHERE update_assignments.id = ? AND update_assignments.status = ?'
        );
        $stmt->execute([$id, $status]);
        return $stmt->fetch() ?: null;
    }

    private function editableTaskTypes(): array
    {
        return [
            'windows_update',
            'bios_update',
            'firmware_update',
            'ram_upgrade',
            'hard_drive_upgrade',
            'battery_replace',
            'software_update',
        ];
    }

    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
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

    private function managedOfficeIds(array $user): array
    {
        if (ScopeService::isAdmin($user)) {
            return [];
        }

        return ScopeService::officeIdsForUser($user);
    }

    private function officeOptionsForAssignment(array $user, array $managedOfficeIds): array
    {
        if (ScopeService::isAdmin($user)) {
            return $this->db->query('SELECT id, name FROM offices ORDER BY name')->fetchAll();
        }

        if (!$managedOfficeIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($managedOfficeIds), '?'));
        $stmt = $this->db->prepare("SELECT id, name FROM offices WHERE id IN ({$placeholders}) ORDER BY name");
        $stmt->execute($managedOfficeIds);
        return $stmt->fetchAll();
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
        $this->ensureColumn('assignment_group_key', 'VARCHAR(40) NULL AFTER id');
        $this->ensureColumn('task_type', 'VARCHAR(80) NULL AFTER task_kind');
        $this->ensureColumn('software_name', 'VARCHAR(255) NULL AFTER task_type');
        $this->ensureColumn('evidence_type', 'VARCHAR(40) NULL AFTER completed_at');
        $this->ensureColumn('evidence_id', 'BIGINT UNSIGNED NULL AFTER evidence_type');
    }

    private function ensureColumn(string $column, string $definition): void
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
