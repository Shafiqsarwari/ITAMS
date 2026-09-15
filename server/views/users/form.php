<?php
$editing = !empty($userRecord);
$protectedAdminSelf = !empty($protectedAdminSelf);
?>
<div class="panel module-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-person-gear"></i></span>
            <div>
                <div class="panel-title mb-1"><?= $editing ? 'Edit User' : 'Create User' ?></div>
                <small class="text-secondary"><?= $editing ? 'Update the user profile and office assignment.' : 'Set the username, password, and office assignment.' ?></small>
            </div>
        </div>
    </div>
    <form method="post" action="<?= $url($editing ? '/users/update' : '/users/store') ?>" class="row g-3 module-form-body">
        <?= Csrf::field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$userRecord['id'] ?>"><?php endif; ?>
        <?php if ($protectedAdminSelf): ?>
            <div class="col-md-6"><label class="form-label">Full Name</label><input class="form-control" name="name" value="<?= e($userRecord['name'] ?? '') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Google Email</label><input class="form-control" type="email" name="email" value="<?= e($userRecord['email'] ?? '') ?>" maxlength="255" required></div>
            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input class="form-control" name="username" value="<?= e($userRecord['username'] ?? '') ?>" minlength="3" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,79}" autocomplete="username" required>
                <div class="form-text">3-80 characters; use letters, numbers, dots, underscores, or hyphens.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">New Password <span class="text-secondary">(optional)</span></label>
                <input class="form-control" type="password" name="password" minlength="8" maxlength="255" autocomplete="new-password">
                <div class="form-text">Leave blank to keep the current password.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Confirm New Password</label>
                <input class="form-control" type="password" name="password_confirmation" minlength="8" maxlength="255" autocomplete="new-password">
            </div>
            <div class="col-md-6"><label class="form-label">Role</label><input class="form-control" value="Admin" disabled></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save User</button><a href="<?= $url('/users') ?>" class="btn btn-outline-secondary">Cancel</a></div>
        <?php else: ?>
            <div class="col-md-6"><label class="form-label">Full Name</label><input class="form-control" name="name" value="<?= e($userRecord['name'] ?? '') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Google Email</label><input class="form-control" type="email" name="email" value="<?= e($userRecord['email'] ?? '') ?>" maxlength="255" required></div>
            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input class="form-control" name="username" value="<?= e($userRecord['username'] ?? '') ?>" minlength="3" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,79}" autocomplete="username" required>
                <div class="form-text">3-80 characters; use letters, numbers, dots, underscores, or hyphens.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= $editing ? 'New Password' : 'Password' ?><?php if ($editing): ?> <span class="text-secondary">(optional)</span><?php endif; ?></label>
                <input class="form-control" type="password" name="password" minlength="8" maxlength="255" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
                <?php if ($editing): ?><div class="form-text">Leave blank to keep the current password.</div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= $editing ? 'Confirm New Password' : 'Confirm Password' ?></label>
                <input class="form-control" type="password" name="password_confirmation" minlength="8" maxlength="255" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Office</label>
                <select class="form-select" name="office_id" data-user-office-select required>
                    <option value="">Select office</option>
                    <?php if (!empty($canAssignAllOffices)): ?>
                        <option value="all" data-admin-all-offices <?= !empty($userRecord) && empty($userRecord['office_id']) ? 'selected' : '' ?>>All Offices</option>
                    <?php endif; ?>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?= (int)$office['id'] ?>" <?= (($userRecord['office_id'] ?? '') == $office['id']) ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save User</button><a href="<?= $url('/users') ?>" class="btn btn-outline-secondary">Cancel</a></div>
        <?php endif; ?>
    </form>
</div>
