<?php
$editing = !empty($assetRecord);
$selectedType = $assetRecord['device_type'] ?? 'Printer';
?>
<div class="panel module-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-router"></i></span>
            <div>
                <div class="panel-title mb-1"><?= $editing ? 'Edit Offline Device' : 'Add Offline Device' ?></div>
                <small class="text-secondary">Maintain device identity, assignment, and firmware details.</small>
            </div>
        </div>
    </div>
    <form method="post" action="<?= $url($editing ? '/device/update' : '/device/store') ?>" class="row g-3 module-form-body" data-office-scoped-form>
        <?= Csrf::field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$assetRecord['id'] ?>"><?php endif; ?>
        <div class="col-md-6">
            <label class="form-label">Device Type</label>
            <select class="form-select" name="device_type">
                <?php foreach (['Printer', 'Scanner', 'Copier Machine', 'Access Point', 'Wireless Router', 'Switch', 'Firewall', 'NVR', 'DVR', 'Security Camera', 'Bridge', 'Repeater', 'Modem', 'Other'] as $type): ?>
                    <option <?= $selectedType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Brand</label>
            <input class="form-control" name="manufacturer" value="<?= e($assetRecord['manufacturer'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Model</label>
            <input class="form-control" name="model" value="<?= e($assetRecord['model'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Serial Number</label>
            <input class="form-control" name="serial_number" value="<?= e($assetRecord['serial_number'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Firmware</label>
            <input class="form-control" name="firmware_version" value="<?= e($assetRecord['firmware_version'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Assign Office</label>
            <select class="form-select assignment-native-select" name="office_id" data-office-source-select data-searchable-assignment-select data-search-placeholder="Search office" required>
                <option value="">Select office</option>
                <?php foreach ($offices as $office): ?>
                    <option value="<?= (int)$office['id'] ?>" <?= (($assetRecord['office_id'] ?? '') == $office['id']) ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Assign User</label>
            <select class="form-select assignment-native-select" name="assigned_employee_id" data-office-scoped-select data-searchable-assignment-select data-search-placeholder="Search employee, designation, or office">
                <option value="">Unassigned</option>
                <?php foreach ($employees as $employee):
                    $label = $employee['name'];
                    if (!empty($employee['designation'])) {
                        $label .= ' - ' . $employee['designation'];
                    }
                    $employeeSearch = trim(implode(' ', array_filter([
                        $employee['name'] ?? null,
                        $employee['designation'] ?? null,
                        $employee['office_name'] ?? null,
                    ])));
                ?>
                    <option value="<?= (int)$employee['id'] ?>" data-office-id="<?= (int)$employee['office_id'] ?>" data-search="<?= e($employeeSearch) ?>" <?= (($assetRecord['assigned_employee_id'] ?? '') == $employee['id']) ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-secondary">One employee can be assigned to several assets.</small>
        </div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary">Save Device</button>
            <a href="<?= $url('/device') ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
