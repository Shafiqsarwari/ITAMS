<section class="panel module-workbench">
    <div class="module-header"><h2>Network Devices</h2></div>
    <div class="module-body">
        <p>Printers, switches and access points reported by your enabled office collector. Missing readings mean the device did not provide them.</p>
        <?php if ($collectors): ?>
        <p><a class="btn btn-outline-primary" href="<?= $url('/agent/download?edition=network-pilot') ?>">Download agent 1.3.3 — network device support</a></p>
        <form method="post" action="<?= $url('/network-devices/configure') ?>" class="d-flex flex-wrap gap-2 mb-3">
            <?= Csrf::field() ?>
            <label>Collector computer
                <select name="computer_id" class="form-select" required>
                    <?php foreach ($collectors as $collector): ?><option value="<?= (int)$collector['id'] ?>"><?= e($collector['computer_name']) ?> (<?= $collector['enabled'] ? 'Enabled' : 'Disabled' ?>)</option><?php endforeach; ?>
                </select>
            </label>
            <label>Authorization<select name="enabled" class="form-select"><option value="0">Disabled</option><option value="1">Enabled</option></select></label>
            <button class="btn btn-primary" type="submit">Save</button>
        </form>
        <p>Enable one collector per office, then run <code>Configure-NetworkDiscovery.ps1</code> as Administrator from its EndpointHealthAgent installation folder. Enter each device IP, type and read-only SNMPv2c community. Polling starts within five minutes. SNMPv3 is not supported in this pilot.</p>
        <?php endif; ?>
        <div class="table-responsive"><table class="table data-table">
            <thead><tr><th>Device / IP</th><th>Office / Collector</th><th>SNMP response</th><th>Model / Serial</th><th>Firmware</th><th>Printer supplies</th><th>Last checked</th></tr></thead>
            <tbody><?php foreach ($networkDevices as $device): $details = json_decode($device['details'] ?? '{}', true) ?: []; ?>
            <tr>
                <td><?= e(($details['name'] ?? '') ?: $device['address']) ?><br><?= e($device['address']) ?><br><?= e($details['kind'] ?? '') ?><details><summary>Description</summary><?= e($details['description'] ?? '') ?></details></td>
                <td><?= e($device['office_name'] ?: 'Unassigned') ?><br><?= e($device['computer_name']) ?></td>
                <td><?= $device['checked_at_epoch'] === null || (int)$device['database_now_epoch'] - (int)$device['checked_at_epoch'] > 1800 ? 'Stale — check collector' : ($device['reachable'] ? 'Responding' : 'Unavailable') ?></td>
                <td><?= e(($details['model'] ?? '') ?: 'Unavailable') ?><br><?= e(($details['serial'] ?? '') ?: 'Unavailable') ?></td>
                <td><?= e(($details['firmware'] ?? '') ?: 'Unavailable') ?></td>
                <td><?php foreach (($details['supplies'] ?? []) as $supply): ?><?= e($supply['name']) ?>: <?= $supply['percent'] === null ? 'Unavailable' : e((string)$supply['percent']) . '%' ?><br><?php endforeach; ?><?php if (empty($details['supplies'])): ?>Unavailable<?php endif; ?></td>
                <td><?= $device['checked_at_epoch'] === null ? 'Unavailable' : e(date('Y-m-d h:i A', (int)$device['checked_at_epoch'])) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php if (!$networkDevices): ?><p>No network devices reported yet.</p><?php endif; ?>
    </div>
</section>
