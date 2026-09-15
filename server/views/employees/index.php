<?php $editing = !empty($editEmployee); ?>

<div class="panel module-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-person-vcard"></i></span>
            <div>
                <div class="panel-title mb-1">Employees</div>
                <small class="text-secondary">Create staff records and assign them to offices.</small>
            </div>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#employeeModal"><i class="bi bi-person-plus"></i> Add Employee</button>
    </div>

    <div class="module-body">
        <?php if (!$employees): ?>
            <div class="empty-state">
                <i class="bi bi-person-vcard"></i>
                <strong>No employees yet</strong>
                <span>Employees appear here after they are added.</span>
            </div>
        <?php else: ?>
            <div class="employee-table-toolbar">
                <div class="table-search table-search-lg" role="search">
                    <i class="bi bi-search"></i>
                    <input class="form-control" type="search" data-table-search="#employeeTable" placeholder="Search employees, employee ID, designation, office">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-table-search-clear aria-label="Clear employee search"><i class="bi bi-x-lg"></i></button>
                </div>
                <span class="employee-count-badge"><?= number_format(count($employees)) ?> employee<?= count($employees) === 1 ? '' : 's' ?></span>
            </div>
            <form id="employeesBulkDelete" class="bulk-delete-toolbar">
                <button class="btn btn-sm btn-outline-danger" type="button" disabled data-bulk-delete-submit data-delete-request data-entity-type="employee" data-bulk-source="employeesBulkDelete" data-entity-label="selected employees">
                    <i class="bi bi-trash"></i> Request deletion
                </button>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle data-table employee-table" id="employeeTable" data-external-search="1">
                <thead>
                    <tr>
                        <th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all employees" data-bulk-select-all data-bulk-target="employeesBulkDelete"></th>
                        <th>Employee</th>
                        <th>Employee ID</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Office</th>
                        <th class="text-center">Assigned Device</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$employee['id'] ?>" form="employeesBulkDelete" aria-label="Select employee" data-bulk-select-item></td>
                        <td data-label="Employee"><strong><?= e($employee['name']) ?></strong></td>
                        <td data-label="Employee ID"><?= e($employee['employee_number'] ?: '-') ?></td>
                        <td data-label="Designation"><?= e($employee['designation'] ?: '-') ?></td>
                        <td data-label="Department"><?= e($employee['department'] ?: '-') ?></td>
                        <td data-label="Office"><?= e($employee['office_name'] ?: '-') ?></td>
                        <td class="text-center" data-label="Assigned Device"><strong><?= number_format((int)($employee['assigned_assets_count'] ?? 0)) ?></strong></td>
                        <td class="text-end" data-label="Actions">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= $url('/employees?edit=' . $employee['id']) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                            <button class="btn btn-sm btn-outline-danger" type="button" title="Request deletion" aria-label="Request deletion of <?= e($employee['name']) ?>" data-delete-request data-entity-type="employee" data-entity-id="<?= (int)$employee['id'] ?>" data-entity-label="<?= e($employee['name']) ?>"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-labelledby="deleteRequestModalLabel" aria-hidden="true" data-delete-request-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/delete-requests/store') ?>" data-delete-request-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="entity_type" data-delete-request-type>
                <input type="hidden" name="redirect_to" value="/employees">
                <div data-delete-request-ids></div>
                <div class="modal-header">
                    <div><h2 class="modal-title fs-5" id="deleteRequestModalLabel"><i class="bi bi-trash"></i> Request Deletion</h2><small class="text-secondary">An authorized user must approve this action.</small></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body compact-form">
                    <div class="alert alert-warning mb-0">You are requesting deletion of <strong data-delete-request-label>the selected item</strong>.</div>
                    <label class="form-label">Assign approval to
                        <select class="form-select" name="assigned_to_user_id" required <?= empty($deleteApprovers) ? 'disabled' : '' ?>>
                            <option value="">Select an authorized user</option>
                            <?php foreach ($deleteApprovers as $approver): ?><option value="<?= (int)$approver['id'] ?>"><?= e($approver['name']) ?><?= !empty($approver['office_name']) ? ' - ' . e($approver['office_name']) : ' - Sysadmin' ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="form-label">Reason (optional)<textarea class="form-control" name="request_note" rows="3" maxlength="1000" placeholder="Explain why this item should be deleted"></textarea></label>
                    <?php if (empty($deleteApprovers)): ?><div class="alert alert-danger mb-0">No authorized approver is currently available. Contact the Sysadmin.</div><?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit" <?= empty($deleteApprovers) ? 'disabled' : '' ?>><i class="bi bi-send"></i> Send Request</button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalLabel" aria-hidden="true" <?= $editing ? 'data-auto-show-modal' : '' ?>>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/employees/save') ?>">
                <?= Csrf::field() ?>
                <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editEmployee['id'] ?>"><?php endif; ?>
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="employeeModalLabel"><?= $editing ? 'Edit Employee' : 'Add Employee' ?></h2>
                    <?php if ($editing): ?>
                        <a href="<?= $url('/employees') ?>" class="btn-close" aria-label="Close"></a>
                    <?php else: ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <?php endif; ?>
                </div>
                <div class="modal-body compact-form">
                    <label class="form-label">
                        Employee name
                        <input class="form-control" name="name" value="<?= e($editEmployee['name'] ?? '') ?>" required>
                    </label>
                    <label class="form-label">
                        Employee ID
                        <input class="form-control" name="employee_number" value="<?= e($editEmployee['employee_number'] ?? '') ?>" maxlength="80" required>
                    </label>
                    <label class="form-label">
                        Designation
                        <input class="form-control" name="designation" value="<?= e($editEmployee['designation'] ?? '') ?>" required>
                    </label>
                    <label class="form-label">
                        Department
                        <input class="form-control" name="department" value="<?= e($editEmployee['department'] ?? '') ?>">
                    </label>
                    <select class="form-select" name="office_id" required>
                        <option value="">Select office</option>
                        <?php foreach ($offices as $office): ?>
                            <option value="<?= (int)$office['id'] ?>" <?= (($editEmployee['office_id'] ?? '') == $office['id']) ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <a href="<?= $url('/employees') ?>" class="btn btn-outline-secondary"><?= $editing ? 'Cancel' : 'Close' ?></a>
                    <button class="btn btn-primary">Save Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>
