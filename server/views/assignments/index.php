<?php
$assignmentTaskLabels = [
    'windows_update' => 'OS update',
    'bios_update' => 'Update BIOS version',
    'firmware_update' => 'Update firmware',
    'ram_upgrade' => 'Upgrade RAM',
    'hard_drive_upgrade' => 'Upgrade hard drive',
    'device_name_change' => 'Change device name',
    'battery_replace' => 'Replace battery',
    'software_update' => 'Update software',
];
$taskLabel = static fn(array $assignment): string => $assignmentTaskLabels[$assignment['task_type'] ?? ''] ?? (ucfirst((string)($assignment['task_kind'] ?? 'update')) . ' update');
$deleteEntityLabels = ['device' => 'Device', 'employee' => 'Employee', 'office' => 'Office'];
$pendingDeleteRequests = array_values(array_filter($deleteRequests ?? [], static fn(array $request): bool => ($request['status'] ?? '') === 'pending'));
$resolvedDeleteRequests = array_values(array_filter($deleteRequests ?? [], static fn(array $request): bool => ($request['status'] ?? '') !== 'pending'));
$deleteRequestLabels = static function (array $request): string {
    $labels = json_decode((string)($request['entity_labels'] ?? ''), true);
    return is_array($labels) && $labels ? implode(', ', array_map('strval', $labels)) : 'Selected item';
};
?>

<div class="assignment-module">
    <section class="panel module-workbench dashboard-update-assignments">
        <div class="module-header">
            <div class="module-heading">
                <span class="module-icon"><i class="bi bi-trash"></i></span>
                <div><div class="panel-title mb-1">Delete Requests</div><small class="text-secondary">Authorized users can approve or reject requested deletions.</small></div>
            </div>
            <span class="soft-pill"><?= number_format(count($pendingDeleteRequests)) ?> pending</span>
        </div>
        <div class="module-body assignment-module-body">
            <?php if (!$pendingDeleteRequests && !$resolvedDeleteRequests): ?>
                <div class="empty-state dashboard-assignment-empty"><i class="bi bi-shield-check"></i><strong>No delete requests</strong><span>Delete approval requests will appear here.</span></div>
            <?php else: ?>
                <?php if ($pendingDeleteRequests): ?>
                    <div class="table-responsive dashboard-assignment-table-wrap">
                        <table class="table align-middle dashboard-assignment-table">
                            <thead><tr><th>Action</th><th>Item</th><th>Requested By</th><th>Assigned To</th><th>Reason</th><th>Requested</th><th>Status</th><th>Decision</th></tr></thead>
                            <tbody>
                            <?php foreach ($pendingDeleteRequests as $request):
                                $isAssignedApprover = (int)$request['assigned_to_user_id'] === (int)($currentUser['id'] ?? 0);
                                $entityLabel = $deleteEntityLabels[$request['entity_type'] ?? ''] ?? 'Item';
                            ?>
                                <tr>
                                    <td data-label="Action"><strong>Delete <?= e($entityLabel) ?></strong></td>
                                    <td data-label="Item"><?= e($deleteRequestLabels($request)) ?></td>
                                    <td data-label="Requested By"><?= e($request['requested_by_name'] ?: '-') ?></td>
                                    <td data-label="Assigned To"><?= e($request['assigned_to_name'] ?: '-') ?></td>
                                    <td data-label="Reason"><?= e($request['request_note'] ?: '-') ?></td>
                                    <td data-label="Requested"><?= e(af_datetime($request['created_at'] ?? null)) ?></td>
                                    <td data-label="Status"><span class="soft-pill">Awaiting decision</span></td>
                                    <td data-label="Decision">
                                        <?php if ($isAssignedApprover): ?>
                                            <div class="assignment-row-actions">
                                                <form method="post" action="<?= $url('/delete-requests/approve') ?>" data-confirm="Approve this request and permanently delete <?= e($deleteRequestLabels($request)) ?>?">
                                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                                                    <button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-check2"></i> Approve</button>
                                                </form>
                                                <form method="post" action="<?= $url('/delete-requests/reject') ?>" data-confirm="Reject this delete request?" data-confirm-action="remove">
                                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-x-lg"></i> Reject</button>
                                                </form>
                                            </div>
                                        <?php else: ?><span class="text-secondary"><i class="bi bi-hourglass-split"></i> Waiting for approver</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($resolvedDeleteRequests): ?>
                    <div class="dashboard-completion-grid assignment-completion-grid mt-3">
                        <?php foreach (array_slice($resolvedDeleteRequests, 0, 20) as $request): ?>
                            <div class="dashboard-completion-item">
                                <i class="bi <?= ($request['status'] ?? '') === 'approved' ? 'bi-check2-circle' : 'bi-x-circle' ?>"></i>
                                <span class="assignment-completion-content"><strong>Delete <?= e($deleteEntityLabels[$request['entity_type'] ?? ''] ?? 'Item') ?> <?= e(ucfirst((string)$request['status'])) ?></strong><small><?= e($deleteRequestLabels($request)) ?> &middot; Approver: <?= e($request['assigned_to_name'] ?: '-') ?></small></span>
                                <time><?= e(af_datetime($request['decided_at'] ?? null)) ?></time>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel module-workbench dashboard-update-assignments">
        <div class="module-header">
            <div class="module-heading">
                <span class="module-icon"><i class="bi bi-person-check"></i></span>
                <div>
                    <div class="panel-title mb-1">Pending Tasks</div>
                    <small class="text-secondary"><?= $canReviewAllAssignments ? 'Pending device and software update work for all users.' : 'Update work assigned to you.' ?></small>
                </div>
            </div>
            <div class="assignment-header-actions">
                <span class="soft-pill"><?= number_format(count($pendingUpdateAssignments)) ?> pending</span>
                <?php if ($canReviewAllAssignments): ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createUpdateAssignment"><i class="bi bi-plus-lg"></i> Assign Task</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="module-body assignment-module-body">
            <?php if (!$pendingUpdateAssignments): ?>
                <div class="empty-state dashboard-assignment-empty">
                    <i class="bi bi-check2-circle"></i>
                    <strong>No pending update tasks</strong>
                    <span>Assigned work appears here until the matching device or software change is detected.</span>
                </div>
            <?php else: ?>
                <div class="table-responsive dashboard-assignment-table-wrap">
                    <table class="table align-middle dashboard-assignment-table">
                        <thead><tr><th>Task</th><th>Device</th><?php if ($canReviewAllAssignments): ?><th>Assigned To</th><?php endif; ?><th>Assigned By</th><th>Note</th><th>Assigned</th><th>Status</th><?php if ($canReviewAllAssignments): ?><th>Actions</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($pendingUpdateAssignments as $assignment):
                            $isOwnAssignment = (int)($assignment['assigned_to_user_id'] ?? 0) === (int)($currentUser['id'] ?? 0);
                        ?>
                            <tr>
                                <td data-label="Task">
                                    <strong><?= e($taskLabel($assignment)) ?></strong>
                                    <?php if (($assignment['task_type'] ?? '') === 'software_update' && !empty($assignment['software_name'])): ?><br><small><?= e($assignment['software_name']) ?></small><?php endif; ?>
                                </td>
                                <td data-label="Device">
                                    <a class="assignment-device-link" href="<?= $url('/device?open_device=' . (int)$assignment['computer_id']) ?>"><strong><?= e($assignment['device_name'] ?: $assignment['computer_name'] ?: '-') ?></strong></a><br>
                                    <small><?= e($assignment['office_name'] ?: 'Unassigned office') ?></small>
                                </td>
                                <?php if ($canReviewAllAssignments): ?>
                                    <td data-label="Assigned To"><?= e($assignment['assigned_to_name'] ?: '-') ?><br><small><?= e($assignment['assigned_to_email'] ?: '-') ?></small></td>
                                <?php endif; ?>
                                <td data-label="Assigned By"><?= e($assignment['assigned_by_name'] ?: '-') ?></td>
                                <td data-label="Note"><?= e($assignment['note'] ?: '-') ?></td>
                                <td data-label="Assigned"><?= e(af_datetime($assignment['created_at'] ?? null)) ?></td>
                                <td data-label="Status"><span class="soft-pill">Waiting for change</span></td>
                                <?php if ($canReviewAllAssignments): ?>
                                    <td data-label="Actions">
                                        <?php if ($isOwnAssignment): ?>
                                            <span class="text-secondary" title="Assigned users cannot edit or cancel their own tasks"><i class="bi bi-lock"></i> Assigned to you</span>
                                        <?php else: ?>
                                            <div class="assignment-row-actions">
                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                type="button"
                                                title="Edit task"
                                                aria-label="Edit task"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editUpdateAssignment"
                                                data-edit-update-assignment
                                                data-assignment-id="<?= (int)$assignment['id'] ?>"
                                                data-task-type="<?= e($assignment['task_type'] ?? '') ?>"
                                                data-office-id="<?= (int)($assignment['office_id'] ?? 0) ?>"
                                                data-device-id="<?= (int)$assignment['computer_id'] ?>"
                                                data-user-id="<?= (int)$assignment['assigned_to_user_id'] ?>"
                                                data-software-name="<?= e($assignment['software_name'] ?? '') ?>"
                                                data-note="<?= e($assignment['note'] ?? '') ?>"
                                            ><i class="bi bi-pencil"></i></button>
                                            <form method="post" action="<?= $url('/task/cancel') ?>" data-confirm="Cancel this pending task?" data-confirm-action="remove">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$assignment['id'] ?>">
                                                <button class="btn btn-sm btn-outline-warning" type="submit" title="Cancel task" aria-label="Cancel task"><i class="bi bi-x-circle"></i></button>
                                            </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel module-workbench dashboard-update-assignments">
        <div class="module-header">
            <div class="module-heading">
                <span class="module-icon"><i class="bi bi-bell"></i></span>
                <div>
                    <div class="panel-title mb-1"><?= $canReviewAllAssignments ? 'Completed Notifications' : 'Completed Tasks' ?></div>
                    <small class="text-secondary"><?= $canReviewAllAssignments ? 'Tasks completed after matching change evidence was received.' : 'Your completed tasks after matching evidence was received.' ?></small>
                </div>
            </div>
            <span class="soft-pill"><?= number_format(count($completedUpdateAssignments)) ?> completed</span>
        </div>
        <div class="module-body assignment-module-body">
            <?php if (!$completedUpdateAssignments): ?>
                <div class="empty-state dashboard-assignment-empty">
                    <i class="bi bi-bell"></i>
                    <strong>No completed tasks yet</strong>
                </div>
            <?php else: ?>
                <?php if ($canReviewAllAssignments): ?>
                    <form id="completedTaskBulkDelete" class="bulk-delete-toolbar" method="post" action="<?= $url('/task/delete-completed') ?>" data-confirm="Delete selected completed tasks?">
                        <?= Csrf::field() ?>
                        <label class="bulk-select-all-label">
                            <input class="form-check-input" type="checkbox" aria-label="Select all completed tasks" data-bulk-select-all data-bulk-target="completedTaskBulkDelete">
                            Select all
                        </label>
                        <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                            <i class="bi bi-trash"></i> Delete selected
                        </button>
                    </form>
                <?php endif; ?>
                <div class="dashboard-completion-grid assignment-completion-grid">
                    <?php foreach ($completedUpdateAssignments as $completed):
                        $completedItemName = trim((string)($completed['completed_item_name'] ?? ''));
                        $previousValue = trim((string)($completed['evidence_previous_value'] ?? ''));
                        $currentValue = trim((string)($completed['evidence_current_value'] ?? ''));
                        if (($completed['software_change_type'] ?? '') === 'installed' && $previousValue === '') {
                            $previousValue = 'Not installed';
                        }
                        if (($completed['software_change_type'] ?? '') === 'removed' && $currentValue === '') {
                            $currentValue = 'Removed';
                        }
                    ?>
                        <div class="dashboard-completion-item<?= $canReviewAllAssignments ? ' has-selection' : '' ?>">
                            <?php if ($canReviewAllAssignments): ?>
                                <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$completed['id'] ?>" form="completedTaskBulkDelete" aria-label="Select completed task" data-bulk-select-item>
                            <?php endif; ?>
                            <i class="bi bi-check2-circle"></i>
                            <span class="assignment-completion-content">
                                <strong><?= e($taskLabel($completed)) ?></strong>
                                <span class="assignment-completion-details">
                                    <small><b>Device:</b> <a class="assignment-device-link" href="<?= $url('/device?open_device=' . (int)$completed['computer_id']) ?>"><?= e($completed['device_name'] ?: $completed['computer_name'] ?: '-') ?></a></small>
                                    <?php if ($completedItemName !== ''): ?><small><b>Changed item:</b> <?= e($completedItemName) ?></small><?php endif; ?>
                                    <small><b>Old value/version:</b> <?= e($previousValue !== '' ? $previousValue : '-') ?></small>
                                    <small><b>New value/version:</b> <?= e($currentValue !== '' ? $currentValue : '-') ?></small>
                                    <small><b>Completed by:</b> <?= e($completed['assigned_to_name'] ?: '-') ?></small>
                                </span>
                            </span>
                            <time><?= e(af_datetime($completed['completed_at'] ?? null)) ?></time>
                            <?php if ($canReviewAllAssignments): ?>
                                <form method="post" action="<?= $url('/task/delete-completed') ?>" data-confirm="Delete this completed task?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$completed['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete completed task" aria-label="Delete completed task"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php if ($canReviewAllAssignments): ?>
<div class="modal fade" id="editUpdateAssignment" tabindex="-1" aria-labelledby="editUpdateAssignmentLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered assignment-modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= $url('/task/update') ?>" data-update-assignment-form data-update-assignment-edit-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="id" data-assignment-edit-id>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="editUpdateAssignmentLabel">Edit Task</h2>
                        <small class="text-secondary">Update the task type, device, user, software, or note while the task is pending.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body assignment-form-grid">
                    <div class="assignment-picker">
                        <label class="form-label" for="editAssignmentType">Task Type</label>
                        <select class="form-select" id="editAssignmentType" name="task_type" data-assignment-type required>
                            <option value="">Select task type</option>
                            <option value="software_update">Software update</option>
                            <option value="bios_update">BIOS version update</option>
                            <option value="firmware_update">Firmware update</option>
                            <option value="windows_update">OS update</option>
                            <option value="ram_upgrade">RAM upgrade</option>
                            <option value="hard_drive_upgrade">Hard disk upgrade</option>
                            <option value="battery_replace">Battery replacement</option>
                        </select>
                    </div>
                    <div class="assignment-picker">
                        <label class="form-label" for="editAssignmentOffice">Office</label>
                        <select class="form-select assignment-native-select" id="editAssignmentOffice" name="office_id" data-assignment-office data-searchable-assignment-select data-search-placeholder="Search office" required>
                            <option value="">Select office</option>
                            <?php foreach ($assignmentOffices as $office): ?>
                                <option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker">
                        <label class="form-label" for="editAssignmentDevice">Device</label>
                        <select class="form-select assignment-native-select" id="editAssignmentDevice" name="device_id" data-assignment-device data-searchable-assignment-select data-search-placeholder="Search serial, device, user, office" required>
                            <option value="">Select device</option>
                            <?php foreach ($assignmentDevices as $device):
                                $deviceLabel = trim((string)($device['label'] ?: $device['computer_name'] ?: 'Device'));
                                $serialLabel = trim((string)($device['serial_number'] ?: '-'));
                                $deviceSearch = trim(implode(' ', array_filter([
                                    $deviceLabel,
                                    $serialLabel,
                                ])));
                            ?>
                                <option value="<?= (int)$device['id'] ?>" data-office-id="<?= (int)($device['office_id'] ?? 0) ?>" data-search="<?= e($deviceSearch) ?>">
                                    <?= e($deviceLabel . ' / Serial ' . $serialLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker">
                        <label class="form-label" for="editAssignmentUser">User</label>
                        <select class="form-select assignment-native-select" id="editAssignmentUser" name="assigned_to_user_id" data-assignment-user data-searchable-assignment-select data-search-placeholder="Search user or office" required>
                            <option value="">Select user</option>
                            <?php foreach ($assignmentUsers as $optionUser):
                                $userLabel = trim((string)($optionUser['name'] ?? '-')) ?: '-';
                            ?>
                                <option value="<?= (int)$optionUser['id'] ?>" data-office-id="<?= (int)($optionUser['office_id'] ?? 0) ?>" <?= (($optionUser['role_slug'] ?? '') === 'admin' && empty($optionUser['office_id'])) ? 'data-all-offices="1"' : '' ?> data-search="<?= e($userLabel) ?>">
                                    <?= e($userLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker assignment-software-picker" data-assignment-software-wrap hidden>
                        <label class="form-label" for="editAssignmentSoftware">Software</label>
                        <select class="form-select assignment-native-select" id="editAssignmentSoftware" name="software_name" data-assignment-software data-searchable-assignment-select data-search-placeholder="Search software name">
                            <option value="">Select software</option>
                            <?php foreach ($assignmentSoftware as $software):
                                $softwareName = trim((string)($software['name'] ?? ''));
                                $softwareVersion = trim((string)($software['version'] ?? ''));
                                $softwareLabel = $softwareVersion !== '' ? $softwareName . ' / Version ' . $softwareVersion : $softwareName;
                            ?>
                                <option value="<?= e($softwareName) ?>" data-device-id="<?= (int)$software['computer_id'] ?>" data-search="<?= e(trim($softwareName . ' ' . $softwareVersion)) ?>"><?= e($softwareLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker assignment-note-picker">
                        <label class="form-label" for="editAssignmentNote">Note</label>
                        <textarea class="form-control" id="editAssignmentNote" name="note" rows="3" placeholder="Optional update instructions" data-assignment-note></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="bi bi-floppy"></i> Save Task</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="createUpdateAssignment" tabindex="-1" aria-labelledby="createUpdateAssignmentLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered assignment-modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= $url('/device/update-assignments/store') ?>" data-update-assignment-form>
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="createUpdateAssignmentLabel">Assign Task</h2>
                        <small class="text-secondary">Choose the update work, office, device, user, and matching software when needed.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body assignment-form-grid">
                    <div class="assignment-picker">
                        <label class="form-label" for="assignmentType">Task Type</label>
                        <select class="form-select" id="assignmentType" name="task_types[]" data-assignment-type required>
                            <option value="">Select task type</option>
                            <option value="software_update">Software update</option>
                            <option value="bios_update">BIOS version update</option>
                            <option value="firmware_update">Firmware update</option>
                            <option value="windows_update">OS update</option>
                            <option value="ram_upgrade">RAM upgrade</option>
                            <option value="hard_drive_upgrade">Hard disk upgrade</option>
                            <option value="battery_replace">Battery replacement</option>
                        </select>
                    </div>
                    <div class="assignment-picker">
                        <label class="form-label" for="assignmentOffice">Office</label>
                        <select class="form-select assignment-native-select" id="assignmentOffice" name="office_id" data-assignment-office data-searchable-assignment-select data-search-placeholder="Search office" required>
                            <option value="">Select office</option>
                            <?php foreach ($assignmentOffices as $office): ?>
                                <option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker">
                        <label class="form-label" for="assignmentDevice">Device</label>
                        <select class="form-select assignment-native-select" id="assignmentDevice" name="device_id" data-assignment-device data-searchable-assignment-select data-search-placeholder="Search serial, device, user, office" required>
                            <option value="">Select device</option>
                            <?php foreach ($assignmentDevices as $device):
                                $deviceLabel = trim((string)($device['label'] ?: $device['computer_name'] ?: 'Device'));
                                $serialLabel = trim((string)($device['serial_number'] ?: '-'));
                                $deviceSearch = trim(implode(' ', array_filter([
                                    $deviceLabel,
                                    $serialLabel,
                                ])));
                            ?>
                                <option value="<?= (int)$device['id'] ?>" data-office-id="<?= (int)($device['office_id'] ?? 0) ?>" data-search="<?= e($deviceSearch) ?>">
                                    <?= e($deviceLabel . ' / Serial ' . $serialLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker">
                        <label class="form-label" for="assignmentUser">User</label>
                        <select class="form-select assignment-native-select" id="assignmentUser" name="assigned_to_user_id" data-assignment-user data-searchable-assignment-select data-search-placeholder="Search user or office" required>
                            <option value="">Select user</option>
                            <?php foreach ($assignmentUsers as $optionUser):
                                $userLabel = trim((string)($optionUser['name'] ?? '-')) ?: '-';
                            ?>
                                <option value="<?= (int)$optionUser['id'] ?>" data-office-id="<?= (int)($optionUser['office_id'] ?? 0) ?>" <?= (($optionUser['role_slug'] ?? '') === 'admin' && empty($optionUser['office_id'])) ? 'data-all-offices="1"' : '' ?> data-search="<?= e($userLabel) ?>">
                                    <?= e($userLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker assignment-software-picker" data-assignment-software-wrap hidden>
                        <label class="form-label" for="assignmentSoftware">Software</label>
                        <select class="form-select assignment-native-select" id="assignmentSoftware" name="software_name" data-assignment-software data-searchable-assignment-select data-search-placeholder="Search software name">
                            <option value="">Select software</option>
                            <?php foreach ($assignmentSoftware as $software):
                                $softwareName = trim((string)($software['name'] ?? ''));
                                $softwareVersion = trim((string)($software['version'] ?? ''));
                                $softwareLabel = $softwareVersion !== '' ? $softwareName . ' / Version ' . $softwareVersion : $softwareName;
                            ?>
                                <option value="<?= e($softwareName) ?>" data-device-id="<?= (int)$software['computer_id'] ?>" data-search="<?= e(trim($softwareName . ' ' . $softwareVersion)) ?>"><?= e($softwareLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="assignment-picker assignment-note-picker">
                        <label class="form-label" for="assignmentNote">Note</label>
                        <textarea class="form-control" id="assignmentNote" name="note" rows="3" placeholder="Optional update instructions"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="bi bi-person-check"></i> Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
