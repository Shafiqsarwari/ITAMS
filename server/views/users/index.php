<?php
$presenceLabel = static function (array $row): string {
    if (!empty($row['is_online'])) {
        return 'Online';
    }

    $lastSeen = trim((string)($row['last_seen_at'] ?? ''));
    if ($lastSeen === '') {
        return 'No activity';
    }

    try {
        $lastSeenAt = new DateTimeImmutable($lastSeen);
    } catch (Throwable) {
        return 'No activity';
    }

    $seconds = max(0, time() - $lastSeenAt->getTimestamp());
    if ($seconds < 60) {
        return 'Active just now';
    }

    $units = [
        'year' => 31536000,
        'month' => 2592000,
        'week' => 604800,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
    ];
    foreach ($units as $unit => $unitSeconds) {
        $count = intdiv($seconds, $unitSeconds);
        if ($count > 0) {
            return 'Active ' . $count . ' ' . $unit . ($count === 1 ? '' : 's') . ' ago';
        }
    }

    return 'Active just now';
};
?>
<div class="panel module-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-people"></i></span>
            <div class="panel-title mb-0">Users</div>
        </div>
        <?php if ($canManageUsers): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="bi bi-person-plus"></i> New User</button><?php endif; ?>
    </div>
    <div class="module-body">
        <div class="employee-table-toolbar">
            <div class="table-search table-search-lg" role="search">
                <i class="bi bi-search"></i>
                <input class="form-control" type="search" data-table-search="#usersTable" placeholder="Search users, email, role, office, presence">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-table-search-clear aria-label="Clear user search"><i class="bi bi-x-lg"></i></button>
            </div>
            <span class="employee-count-badge"><?= number_format(count($users)) ?> user<?= count($users) === 1 ? '' : 's' ?></span>
        </div>
        <?php if ($canManageUsers): ?><form id="usersBulkDelete" class="bulk-delete-toolbar" method="post" action="<?= $url('/users/delete') ?>" data-confirm="Delete selected users?">
            <?= Csrf::field() ?>
            <button class="btn btn-sm btn-outline-danger" type="submit" disabled data-bulk-delete-submit>
                <i class="bi bi-trash"></i> Delete selected
            </button>
        </form><?php endif; ?>
        <div class="table-responsive">
            <table class="table align-middle data-table user-table" id="usersTable" data-external-search="1">
            <thead><tr><?php if ($canManageUsers): ?><th class="selection-cell"><input class="form-check-input" type="checkbox" aria-label="Select all users" data-bulk-select-all data-bulk-target="usersBulkDelete"></th><?php endif; ?><th>Name</th><th>Google Email</th><th>Role</th><th>Office</th><th>Status</th><th>Presence</th><th class="user-actions-heading"></th></tr></thead>
            <tbody>
            <?php foreach ($users as $row): ?>
                <?php $protectedAdmin = !empty($protectedAdminId) && (int)$protectedAdminId === (int)$row['id']; ?>
                <?php $protectedAdminSelf = $protectedAdmin && (int)$row['id'] === (int)($_SESSION['user_id'] ?? 0); ?>
                <?php $bulkDeletable = !$protectedAdmin && (int)$row['id'] !== (int)($_SESSION['user_id'] ?? 0); ?>
                <?php $isOnline = !empty($row['is_online']); ?>
                <?php $presenceText = $presenceLabel($row); ?>
                <?php $canOpenMfa = $canResetAnyMfa; ?>
                <tr>
                    <?php if ($canManageUsers): ?><td class="selection-cell" data-label="Select">
                        <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" form="usersBulkDelete" aria-label="Select user" data-bulk-select-item<?= $bulkDeletable ? '' : ' disabled' ?>>
                    </td><?php endif; ?>
                    <td data-label="Name">
                        <div class="user-cell">
                            <span class="user-avatar"><?= e(strtoupper(substr((string)$row['name'], 0, 1))) ?></span>
                            <strong><?= e($row['name']) ?></strong>
                        </div>
                    </td>
                    <td data-label="Google Email"><span class="user-email"><?= e($row['email']) ?></span></td>
                    <td class="user-role-cell" data-label="Role"><span class="soft-pill"><?= e($row['role_name']) ?></span></td>
                    <td data-label="Office"><?= e($row['office_name'] ?: 'All Offices') ?></td>
                    <td data-label="Status"><span class="badge text-bg-<?= $row['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($row['status'])) ?></span></td>
                    <td data-label="Presence"><span class="presence-badge <?= $isOnline ? 'is-online' : 'is-offline' ?>" title="<?= e($row['last_seen_at'] ? 'Last active ' . af_datetime($row['last_seen_at']) : 'No activity recorded') ?>"><i class="bi bi-circle-fill"></i><?= e($presenceText) ?></span></td>
                    <td class="text-end" data-label="Actions">
                        <div class="dropdown table-actions user-actions device-actions">
                            <button class="btn btn-sm btn-outline-secondary user-actions-toggle device-actions-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User actions"><i class="bi bi-three-dots-vertical"></i></button>
                            <div class="dropdown-menu dropdown-menu-end user-actions-menu device-actions-menu">
                                <?php if ($canOpenMfa): ?>
                                    <a class="dropdown-item" href="<?= $url('/users?mfa=profile&mfa_user=' . (int)$row['id']) ?>"><i class="bi bi-shield-lock"></i> Multi-factor authentication</a>
                                <?php endif; ?>
                                <?php if (!empty($canManageUserPermissions)): ?>
                                    <a class="dropdown-item" href="<?= $url('/users?permissions_user=' . (int)$row['id']) ?>"><i class="bi bi-key"></i> Permissions</a>
                                <?php endif; ?>
                                <?php if ($canManageUsers): ?>
                                <a class="dropdown-item" href="<?= $url('/users/edit?id=' . $row['id']) ?>"<?= $protectedAdmin && !$protectedAdminSelf ? ' data-blocked-user-action="This is the only admin in the system. You are not able to edit this user."' : '' ?>><i class="bi bi-pencil"></i> Edit</a>
                                <?php if ($row['status'] === 'active'): ?>
                                    <form method="post" action="<?= $url('/users/disable') ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="dropdown-item" type="submit"<?= $protectedAdmin ? ' data-blocked-user-action="This is the only admin in the system. You are not able to disable this user."' : '' ?>><i class="bi bi-pause-circle"></i> Disable</button></form>
                                <?php else: ?>
                                    <form method="post" action="<?= $url('/users/enable') ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="dropdown-item" type="submit"><i class="bi bi-play-circle"></i> Enable</button></form>
                                <?php endif; ?>
                                <form method="post" action="<?= $url('/users/delete') ?>" data-confirm="Delete this user?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="dropdown-item text-danger" type="submit"<?= $protectedAdmin ? ' data-blocked-user-action="This is the only admin in the system. You are not able to delete this user."' : '' ?>><i class="bi bi-trash"></i> Delete</button></form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canManageUsers): ?><div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/users/store') ?>">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="createUserModalLabel">Create User</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Google Email</label>
                            <input class="form-control" type="email" name="email" maxlength="255" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input class="form-control" name="username" minlength="3" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,79}" autocomplete="username" required>
                            <div class="form-text">3–80 characters; use letters, numbers, dots, underscores, or hyphens.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input class="form-control" type="password" name="password" minlength="8" maxlength="255" autocomplete="new-password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input class="form-control" type="password" name="password_confirmation" minlength="8" maxlength="255" autocomplete="new-password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Office</label>
                            <select class="form-select" name="office_id" data-user-office-select required>
                                <option value="">Select office</option>
                                <?php if (!empty($canAssignAllOffices)): ?>
                                    <option value="all" data-admin-all-offices>All Offices</option>
                                <?php endif; ?>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div><?php endif; ?>

<?php if ($mfaTarget): ?>
<?php
$mfaTargetId = (int)$mfaTarget['id'];
$mfaIsSelf = $mfaTargetId === (int)($_SESSION['user_id'] ?? 0);
?>
<div class="modal fade sidebar-tool-modal mfa-profile-modal" id="userMfaModal" tabindex="-1" aria-labelledby="userMfaModalLabel" aria-hidden="true" data-mfa-auto-open="true" data-mfa-container>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="userMfaModalLabel"><i class="bi bi-person-shield"></i> Multi-factor authentication</h2>
                    <p class="text-secondary small mb-0"><?= e($mfaTarget['name']) ?> · <?= e($mfaTarget['email']) ?></p>
                </div>
                <a class="btn-close" href="<?= $url('/users') ?>" aria-label="Close"></a>
            </div>
            <div class="modal-body mfa-profile-body">
                <?php foreach ($flash as $message): ?>
                    <?php $flashType = in_array($message['type'] ?? '', ['success', 'info', 'warning', 'danger'], true) ? $message['type'] : 'info'; ?>
                    <div class="alert alert-<?= e($flashType) ?> mb-0" role="alert"><?= e($message['message'] ?? '') ?></div>
                <?php endforeach; ?>

                <?php if ($mfaEnabled): ?>
                    <div class="mfa-status is-enabled"><i class="bi bi-shield-check"></i><div><strong>MFA is enabled</strong><span>Resetting removes <?= $mfaIsSelf ? 'your' : "this user's" ?> authenticator and recovery codes. MFA must be enrolled again at the next password login.</span></div></div>
                    <form method="post" action="<?= $url('/users/mfa/reset') ?>" data-confirm="Reset multi-factor authentication for <?= $mfaIsSelf ? 'your account' : e($mfaTarget['name']) ?>?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="user_id" value="<?= $mfaTargetId ?>">
                        <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-arrow-counterclockwise"></i> <?= $mfaIsSelf ? 'Reset My MFA' : 'Reset MFA' ?></button>
                    </form>
                <?php else: ?>
                    <div class="mfa-status"><i class="bi bi-shield"></i><div><strong>MFA is not enabled</strong><span>No reset is required. This user will be required to configure an authenticator app at the next password login.</span></div></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-secondary" href="<?= $url('/users') ?>">Close</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($permissionTarget)): ?>
<?php $permissionTargetIsSysadmin = ScopeService::isAdmin($permissionTarget); ?>
<div class="modal fade sidebar-tool-modal" id="userPermissionsModal" tabindex="-1" aria-labelledby="userPermissionsModalLabel" aria-hidden="true" data-auto-open-modal="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $url('/users/permissions') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$permissionTarget['id'] ?>">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="userPermissionsModalLabel"><i class="bi bi-key"></i> User Permissions</h2>
                        <p class="text-secondary small mb-0"><?= e($permissionTarget['name']) ?> &middot; <?= e($permissionTarget['email']) ?></p>
                    </div>
                    <a class="btn-close" href="<?= $url('/users') ?>" aria-label="Close"></a>
                </div>
                <div class="modal-body">
                    <?php foreach ($flash as $message): ?>
                        <?php $flashType = in_array($message['type'] ?? '', ['success', 'info', 'warning', 'danger'], true) ? $message['type'] : 'info'; ?>
                        <div class="alert alert-<?= e($flashType) ?>" role="alert"><?= e($message['message'] ?? '') ?></div>
                    <?php endforeach; ?>

                    <?php if ($permissionTargetIsSysadmin): ?>
                        <div class="alert alert-info mb-0"><i class="bi bi-shield-check"></i> Sysadmin accounts have all delete permissions automatically.</div>
                    <?php else: ?>
                        <p class="text-secondary small">Grant only the delete actions this user needs. Office access rules still limit which records the user can delete.</p>
                        <div class="list-group permission-option-list">
                            <?php foreach ($permissionOptions as $slug => $option): ?>
                                <label class="list-group-item d-flex gap-3 align-items-start">
                                    <input class="form-check-input mt-1" type="checkbox" name="permissions[]" value="<?= e($slug) ?>" <?= in_array($slug, $grantedPermissions, true) ? 'checked' : '' ?>>
                                    <span>
                                        <strong class="d-block"><i class="bi <?= e($option['icon']) ?>"></i> <?= e($option['name']) ?></strong>
                                        <small class="text-secondary"><?= e($option['description']) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-outline-secondary" href="<?= $url('/users') ?>">Close</a>
                    <?php if (!$permissionTargetIsSysadmin): ?><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> Save Permissions</button><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
