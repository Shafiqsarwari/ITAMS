<?php

class OfficeController extends Controller
{
    public function index(): void
    {
        $user = $this->requirePermission('offices.manage');
        $this->requireAdminRole($user);
        $this->ensureOfficeHierarchyColumn();
        $editOffice = null;
        $editId = (int)Request::input('edit', 0);
        if ($editId > 0 && ScopeService::canManageOffice($user, $editId)) {
            $stmt = $this->db->prepare('SELECT * FROM offices WHERE id = ?');
            $stmt->execute([$editId]);
            $editOffice = $stmt->fetch() ?: null;
        }

        $visibleOfficeIds = ScopeService::isAdmin($user) ? [] : ScopeService::officeIdsForUser($user);
        if (!ScopeService::isAdmin($user) && !$visibleOfficeIds) {
            $visibleOfficeIds = [0];
        }
        $officeWhere = ScopeService::isAdmin($user)
            ? ''
            : 'WHERE offices.id IN (' . implode(',', array_fill(0, count($visibleOfficeIds), '?')) . ')';
        $stmt = $this->db->prepare(
            'SELECT offices.*, parent_offices.name AS parent_name,
                (SELECT COUNT(*) FROM users WHERE users.office_id = offices.id) AS users_count,
                (SELECT COUNT(*) FROM computers WHERE computers.office_id = offices.id) AS devices_count
             FROM offices
             LEFT JOIN offices AS parent_offices ON parent_offices.id = offices.parent_id
             ' . $officeWhere . ' ORDER BY offices.name'
        );
        $stmt->execute(ScopeService::isAdmin($user) ? [] : $visibleOfficeIds);
        $offices = $stmt->fetchAll();
        $parentOfficeOptions = ScopeService::isAdmin($user)
            ? $this->db->query('SELECT id, name, parent_id FROM offices ORDER BY name')->fetchAll()
            : $this->officeRowsByIds(ScopeService::officeIdsForUser($user));
        $canEditOffices = true;
        $canDeleteOffices = Auth::can('offices.delete');
        $deleteApprovers = DeleteRequestService::approvers('offices.delete');
        $canCreateRootOffice = ScopeService::isAdmin($user);
        $this->view('offices/index', compact('offices', 'editOffice', 'canEditOffices', 'canDeleteOffices', 'deleteApprovers', 'parentOfficeOptions', 'canCreateRootOffice') + ['title' => 'Offices']);
    }

    public function save(): void
    {
        $user = $this->requirePermission('offices.manage');
        $this->requireAdminRole($user);
        Csrf::verify();
        $this->ensureOfficeHierarchyColumn();
        $id = (int)Request::input('id', 0);
        $parentId = (int)Request::input('parent_id', 0);
        $data = [
            trim((string)Request::input('name')),
            $parentId > 0 ? $parentId : null,
            Request::input('address') ?: null,
            Request::input('notes') ?: null,
        ];

        if ($data[0] === '') {
            $this->flash('danger', 'Office name is required.');
            Response::redirect('/offices');
        }

        if ($parentId > 0 && !$this->isValidParentOffice($id, $parentId)) {
            $this->flash('danger', 'Select a valid parent office.');
            Response::redirect('/offices' . ($id > 0 ? '?edit=' . $id : ''));
        }
        if (!ScopeService::isAdmin($user) && $id <= 0 && $parentId <= 0) {
            $this->flash('danger', 'Office admins must select a parent office in their office tree.');
            Response::redirect('/offices' . ($id > 0 ? '?edit=' . $id : ''));
        }
        if (!ScopeService::isAdmin($user) && $parentId > 0 && !ScopeService::canManageOffice($user, $parentId)) {
            $this->flash('danger', 'You can only select a parent office in your office tree.');
            Response::redirect('/offices' . ($id > 0 ? '?edit=' . $id : ''));
        }

        if ($id > 0) {
            if (!ScopeService::canManageOffice($user, $id)) {
                $this->flash('danger', 'You can only edit offices in your office tree.');
                Response::redirect('/offices');
            }
            $stmt = $this->db->prepare('UPDATE offices SET name = ?, parent_id = ?, address = ?, notes = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([...$data, $id]);
            Audit::log('updated', 'office', $id);
        } else {
            if (!ScopeService::isAdmin($user) && ($parentId <= 0 || !ScopeService::canManageOffice($user, $parentId))) {
                $this->flash('danger', 'You can only create offices under your office tree.');
                Response::redirect('/offices');
            }
            $stmt = $this->db->prepare('INSERT INTO offices (name, parent_id, address, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute($data);
            Audit::log('created', 'office', (int)$this->db->lastInsertId());
        }

        $this->flash('success', 'Office saved.');
        Response::redirect('/offices');
    }

    public function delete(): void
    {
        $user = $this->requirePermission('offices.delete');
        Csrf::verify();
        $this->flash('info', 'Office deletion requires approval. Submit a delete request from Offices.');
        Response::redirect('/offices');
        $ids = $this->selectedIds();
        if (!$ids) {
            $this->flash('danger', 'Select at least one office to delete.');
            Response::redirect('/offices');
        }
        foreach ($ids as $id) {
            if (!ScopeService::canManageOffice($user, $id) || (!ScopeService::isAdmin($user) && $id === (int)($user['office_id'] ?? 0))) {
                $this->flash('danger', 'You can only delete child offices in your office tree.');
                Response::redirect('/offices');
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $movedDeviceCount = 0;

        try {
            $this->db->beginTransaction();

            // An office deletion must never delete its assets or leave them assigned
            // to an employee from an office that no longer exists. Move every device
            // into the existing Unassigned workflow before removing the office.
            $unassignDevices = $this->db->prepare(
                "UPDATE computers
                 SET office_id = NULL, assigned_employee_id = NULL, updated_at = NOW()
                 WHERE office_id IN ({$placeholders})"
            );
            $unassignDevices->execute($ids);
            $movedDeviceCount = $unassignDevices->rowCount();

            $clearChildren = $this->db->prepare(
                "UPDATE offices SET parent_id = NULL, updated_at = NOW() WHERE parent_id IN ({$placeholders})"
            );
            $clearChildren->execute($ids);

            $stmt = $this->db->prepare("DELETE FROM offices WHERE id IN ({$placeholders})");
            $stmt->execute($ids);

            foreach ($ids as $id) {
                Audit::log('deleted', 'office', $id);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Office deletion failed: ' . $exception->getMessage());
            $this->flash('danger', 'The office could not be deleted. No devices were changed.');
            Response::redirect('/offices');
        }

        $officeMessage = count($ids) === 1 ? 'Office deleted.' : count($ids) . ' offices deleted.';
        $deviceMessage = $movedDeviceCount === 1
            ? ' 1 device was moved to Unassigned.'
            : ' ' . $movedDeviceCount . ' devices were moved to Unassigned.';
        $this->flash('success', $officeMessage . $deviceMessage);
        Response::redirect('/offices');
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

    private function isValidParentOffice(int $officeId, int $parentId): bool
    {
        if ($parentId <= 0 || $officeId === $parentId) {
            return $parentId <= 0;
        }

        $stmt = $this->db->prepare('SELECT id FROM offices WHERE id = ?');
        $stmt->execute([$parentId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        if ($officeId <= 0) {
            return true;
        }

        return !in_array($parentId, ScopeService::descendantOfficeIds($officeId), true);
    }

    private function ensureOfficeHierarchyColumn(): void
    {
        ScopeService::descendantOfficeIds(1);
    }

    private function requireAdminRole(array $user): void
    {
        if (!in_array($user['role_slug'] ?? '', ['super-admin', 'admin'], true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    private function officeRowsByIds(array $officeIds): array
    {
        if (!$officeIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($officeIds), '?'));
        $stmt = $this->db->prepare("SELECT id, name, parent_id FROM offices WHERE id IN ({$placeholders}) ORDER BY name");
        $stmt->execute($officeIds);
        return $stmt->fetchAll();
    }
}
