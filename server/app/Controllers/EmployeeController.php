<?php

class EmployeeController extends Controller
{
    public function index(): void
    {
        $user = $this->requirePermission('employees.manage');
        $this->ensureEmployeeNumberColumn();
        $editEmployee = null;
        $editId = (int)Request::input('edit', 0);
        if ($editId > 0) {
            $editEmployee = $this->findEmployee($editId, $user);
        }

        [$where, $params] = $this->officeScope($user, 'employees.office_id');
        $stmt = $this->db->prepare(
            'SELECT employees.id, employees.name, employees.employee_number, employees.designation, employees.department, employees.office_id, employees.created_at, employees.updated_at,
                offices.name AS office_name,
                COUNT(computers.id) AS assigned_assets_count,
                GROUP_CONCAT(COALESCE(computers.device_name, computers.computer_name) ORDER BY computers.device_name, computers.computer_name SEPARATOR ", ") AS assigned_assets
             FROM employees
             LEFT JOIN offices ON offices.id = employees.office_id
             LEFT JOIN computers ON computers.assigned_employee_id = employees.id
             WHERE ' . $where . '
             GROUP BY employees.id, employees.name, employees.employee_number, employees.designation, employees.department, employees.office_id, employees.created_at, employees.updated_at, offices.name
             ORDER BY employees.name'
        );
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        $this->view('employees/index', $this->formData($user) + [
            'title' => 'Employees',
            'employees' => $employees,
            'editEmployee' => $editEmployee,
            'canDeleteEmployees' => Auth::can('employees.delete'),
            'deleteApprovers' => DeleteRequestService::approvers('employees.delete'),
        ]);
    }

    public function save(): void
    {
        $user = $this->requirePermission('employees.manage');
        $this->ensureEmployeeNumberColumn();
        Csrf::verify();

        $id = (int)Request::input('id', 0);
        $name = trim((string)Request::input('name'));
        $employeeNumber = trim((string)Request::input('employee_number'));
        $designation = trim((string)Request::input('designation'));
        $department = trim((string)Request::input('department'));
        $officeId = (int)Request::input('office_id');

        if ($name === '') {
            $this->flash('danger', 'Employee name is required.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        if ($employeeNumber === '') {
            $this->flash('danger', 'Employee ID is required.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        if (strlen($employeeNumber) > 80) {
            $this->flash('danger', 'Employee ID must be 80 characters or fewer.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        $duplicateStmt = $this->db->prepare(
            'SELECT id FROM employees WHERE employee_number = ? AND id <> ? LIMIT 1'
        );
        $duplicateStmt->execute([$employeeNumber, $id]);
        if ($duplicateStmt->fetch()) {
            $this->flash('danger', 'Employee ID is already in use.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        if ($designation === '') {
            $this->flash('danger', 'Employee designation is required.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        if ($officeId <= 0) {
            $this->flash('danger', 'Assign the employee to an office.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        if (!ScopeService::canManageOffice($user, $officeId)) {
            $this->flash('danger', 'Selected office is outside your access.');
            Response::redirect($id > 0 ? '/employees?edit=' . $id : '/employees');
        }

        $data = [
            $name,
            $employeeNumber,
            $designation,
            $department ?: null,
            $officeId,
        ];

        if ($id > 0) {
            if (!$this->findEmployee($id, $user)) {
                $this->flash('danger', 'Employee was not found in your office.');
                Response::redirect('/employees');
            }
            $stmt = $this->db->prepare(
                'UPDATE employees
                 SET name = ?, employee_number = ?, designation = ?, department = ?, office_id = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([...$data, $id]);
            $stmt = $this->db->prepare(
                'UPDATE computers
                 JOIN employees ON employees.id = computers.assigned_employee_id
                 SET computers.office_id = employees.office_id, computers.updated_at = NOW()
                 WHERE employees.id = ?'
            );
            $stmt->execute([$id]);
            Audit::log('updated', 'employee', $id);
            $this->flash('success', 'Employee updated.');
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO employees (name, employee_number, designation, department, office_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute($data);
            Audit::log('created', 'employee', (int)$this->db->lastInsertId());
            $this->flash('success', 'Employee added.');
        }

        Response::redirect('/employees');
    }

    public function delete(): void
    {
        $user = $this->requirePermission('employees.delete');
        Csrf::verify();
        $this->flash('info', 'Employee deletion requires approval. Submit a delete request from Employees.');
        Response::redirect('/employees');
        $ids = $this->selectedIds();
        if (!$ids) {
            $this->flash('danger', 'Select at least one employee to delete.');
            Response::redirect('/employees');
        }

        $deleteIds = [];
        foreach ($ids as $id) {
            if ($this->findEmployee($id, $user)) {
                $deleteIds[] = $id;
            }
        }
        if (!$deleteIds) {
            $this->flash('danger', 'Employee was not found in your office.');
            Response::redirect('/employees');
        }
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM employees WHERE id IN ({$placeholders})");
        $stmt->execute($deleteIds);
        foreach ($deleteIds as $id) {
            Audit::log('deleted', 'employee', $id);
        }
        $this->flash('success', count($deleteIds) === 1 ? 'Employee deleted.' : count($deleteIds) . ' employees deleted.');
        Response::redirect('/employees');
    }

    private function formData(array $user): array
    {
        if (ScopeService::isAdmin($user)) {
            return ['offices' => $this->db->query('SELECT * FROM offices ORDER BY name')->fetchAll()];
        }

        $officeIds = ScopeService::officeIdsForUser($user);
        if (!$officeIds) {
            return ['offices' => []];
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM offices WHERE id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ') ORDER BY name'
        );
        $stmt->execute($officeIds);
        return ['offices' => $stmt->fetchAll()];
    }

    private function findEmployee(int $id, array $user): ?array
    {
        [$officeWhere, $officeParams] = $this->officeScope($user, 'office_id');
        $where = 'id = ? AND ' . $officeWhere;
        $params = array_merge([$id], $officeParams);
        $stmt = $this->db->prepare("SELECT * FROM employees WHERE {$where}");
        $stmt->execute($params);
        $employee = $stmt->fetch();
        return $employee ?: null;
    }

    private function officeScope(array $user, string $column): array
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

    private function ensureEmployeeNumberColumn(): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "employees"
               AND COLUMN_NAME = "employee_number"'
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $this->db->exec(
                'ALTER TABLE employees ADD COLUMN employee_number VARCHAR(80) NULL AFTER name'
            );
        }
    }

}
