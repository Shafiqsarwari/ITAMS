<?php
$editing = !empty($editOffice);
$invalidParentOfficeIds = $editing ? ScopeService::descendantOfficeIds((int)$editOffice['id']) : [];
?>

<div class="panel module-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-diagram-3"></i></span>
            <div class="panel-title mb-0">Offices</div>
        </div>
        <?php if (!empty($canEditOffices)): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createOfficeModal"><i class="bi bi-building-add"></i> New Office</button>
        <?php endif; ?>
    </div>
    <div class="module-body">
        <div class="employee-table-toolbar">
            <div class="table-search table-search-lg" role="search">
                <i class="bi bi-search"></i>
                <input class="form-control" type="search" data-table-search="#officesTable" placeholder="Search offices, address, users, devices">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-table-search-clear aria-label="Clear office search"><i class="bi bi-x-lg"></i></button>
            </div>
            <span class="employee-count-badge"><?= number_format(count($offices)) ?> office<?= count($offices) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($offices): ?>
            <form id="officesBulkDelete" class="bulk-delete-toolbar">
                <button class="btn btn-sm btn-outline-danger" type="button" disabled data-bulk-delete-submit data-delete-request data-entity-type="office" data-bulk-source="officesBulkDelete" data-entity-label="selected offices">
                    <i class="bi bi-trash"></i> Request deletion
                </button>
            </form>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table align-middle data-table user-table offices-table" id="officesTable" data-external-search="1">
            <thead><tr><th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all offices" data-bulk-select-all data-bulk-target="officesBulkDelete"></th><th>Office</th><th>Parent Office</th><th class="text-center">Users</th><th class="text-center">Device</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($offices as $office): ?>
                <tr>
                    <td class="selection-cell" data-label="Select"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$office['id'] ?>" form="officesBulkDelete" aria-label="Select office" data-bulk-select-item></td>
                    <td data-label="Office">
                        <div class="user-cell">
                            <span class="user-avatar"><i class="bi bi-building"></i></span>
                            <div>
                                <strong><?= e($office['name']) ?></strong><br>
                                <small><?= e($office['address'] ?: '-') ?></small>
                            </div>
                        </div>
                    </td>
                    <td data-label="Parent Office"><?= e($office['parent_name'] ?: '-') ?></td>
                    <td class="text-center" data-label="Users"><span class="soft-pill"><?= (int)$office['users_count'] ?></span></td>
                    <td class="text-center" data-label="Device"><span class="soft-pill"><?= (int)$office['devices_count'] ?></span></td>
                    <td class="text-end" data-label="Actions">
                        <?php if (!empty($canEditOffices)): ?>
                            <div class="table-actions">
                                <?php if (!empty($canEditOffices)): ?><a class="btn btn-sm btn-outline-primary" href="<?= $url('/offices?edit=' . $office['id']) ?>" title="Edit office" aria-label="Edit office"><i class="bi bi-pencil"></i></a><?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger" type="button" title="Request deletion" aria-label="Request deletion of <?= e($office['name']) ?>" data-delete-request data-entity-type="office" data-entity-id="<?= (int)$office['id'] ?>" data-entity-label="<?= e($office['name']) ?>"><i class="bi bi-trash"></i></button>
                            </div>
                        <?php else: ?>
                            <span class="text-secondary">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-labelledby="deleteRequestModalLabel" aria-hidden="true" data-delete-request-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/delete-requests/store') ?>" data-delete-request-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="entity_type" data-delete-request-type>
                <input type="hidden" name="redirect_to" value="/offices">
                <div data-delete-request-ids></div>
                <div class="modal-header"><div><h2 class="modal-title fs-5" id="deleteRequestModalLabel"><i class="bi bi-trash"></i> Request Deletion</h2><small class="text-secondary">An authorized user must approve this action.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body compact-form">
                    <div class="alert alert-warning mb-0">You are requesting deletion of <strong data-delete-request-label>the selected item</strong>. Devices will move to Unassigned if approved.</div>
                    <label class="form-label">Assign approval to
                        <select class="form-select" name="assigned_to_user_id" required <?= empty($deleteApprovers) ? 'disabled' : '' ?>><option value="">Select an authorized user</option><?php foreach ($deleteApprovers as $approver): ?><option value="<?= (int)$approver['id'] ?>"><?= e($approver['name']) ?><?= !empty($approver['office_name']) ? ' - ' . e($approver['office_name']) : ' - Sysadmin' ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="form-label">Reason (optional)<textarea class="form-control" name="request_note" rows="3" maxlength="1000" placeholder="Explain why this office should be deleted"></textarea></label>
                    <?php if (empty($deleteApprovers)): ?><div class="alert alert-danger mb-0">No authorized approver is currently available. Contact the Sysadmin.</div><?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit" <?= empty($deleteApprovers) ? 'disabled' : '' ?>><i class="bi bi-send"></i> Send Request</button></div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($canEditOffices)): ?>
<div class="modal fade" id="createOfficeModal" tabindex="-1" aria-labelledby="createOfficeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/offices/store') ?>">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="createOfficeModalLabel">Create Office</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body compact-form">
                    <input class="form-control" name="name" placeholder="Office name" required>
                    <select class="form-select" name="parent_id">
                        <?php if (!empty($canCreateRootOffice)): ?><option value="">No parent office</option><?php else: ?><option value="">Select parent office</option><?php endif; ?>
                        <?php foreach ($parentOfficeOptions as $parentOffice): ?>
                            <option value="<?= (int)$parentOffice['id'] ?>"><?= e($parentOffice['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control" name="address" placeholder="Address">
                    <textarea class="form-control" name="notes" rows="3" placeholder="Notes"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary">Save Office</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($editing): ?>
<div class="modal fade" id="editOfficeModal" tabindex="-1" aria-labelledby="editOfficeModalLabel" aria-hidden="true" data-auto-show-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/offices/store') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$editOffice['id'] ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="editOfficeModalLabel">Edit Office</h2>
                    <a href="<?= $url('/offices') ?>" class="btn-close" aria-label="Close"></a>
                </div>
                <div class="modal-body compact-form">
                    <input class="form-control" name="name" placeholder="Office name" value="<?= e($editOffice['name'] ?? '') ?>" required>
                    <select class="form-select" name="parent_id">
                        <?php if (!empty($canCreateRootOffice) || empty($editOffice['parent_id'])): ?><option value="">No parent office</option><?php else: ?><option value="">Select parent office</option><?php endif; ?>
                        <?php foreach ($parentOfficeOptions as $parentOffice):
                            $parentOfficeId = (int)$parentOffice['id'];
                            if (in_array($parentOfficeId, $invalidParentOfficeIds, true)) {
                                continue;
                            }
                        ?>
                            <option value="<?= $parentOfficeId ?>" <?= (int)($editOffice['parent_id'] ?? 0) === $parentOfficeId ? 'selected' : '' ?>><?= e($parentOffice['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="form-control" name="address" placeholder="Address" value="<?= e($editOffice['address'] ?? '') ?>">
                    <textarea class="form-control" name="notes" rows="3" placeholder="Notes"><?= e($editOffice['notes'] ?? '') ?></textarea>
                </div>
                <div class="modal-footer">
                    <a href="<?= $url('/offices') ?>" class="btn btn-outline-secondary">Cancel</a>
                    <button class="btn btn-primary">Save Office</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
