<section class="panel module-workbench settings-workbench">
    <div class="module-header">
        <div class="module-heading">
            <span class="module-icon"><i class="bi bi-gear"></i></span>
            <div>
                <div class="panel-title mb-1">Agent Registration</div>
                <small class="text-secondary">Control the key used when a device agent registers with this server.</small>
            </div>
        </div>
        <span class="soft-pill"><?= $hasAgentRegistrationKeyOverride ? 'UI key active' : 'Config key active' ?></span>
    </div>
    <div class="module-body settings-body">
        <form class="settings-secret-form" method="post" action="<?= $url('/settings/agent-registration-key') ?>" autocomplete="off">
            <?= Csrf::field() ?>
            <label class="settings-secret-field">
                <span>New Registration Key</span>
                <input class="form-control" type="password" name="agent_registration_key" minlength="32" required autocomplete="new-password">
            </label>
            <label class="settings-secret-field">
                <span>Confirm Registration Key</span>
                <input class="form-control" type="password" name="agent_registration_key_confirmation" minlength="32" required autocomplete="new-password">
            </label>
            <div class="settings-secret-actions">
                <button class="btn btn-primary" type="submit"><i class="bi bi-shield-lock"></i> Update Key</button>
            </div>
        </form>
        <section class="settings-agent-download" aria-labelledby="agentInstallerTitle">
            <span class="module-icon"><i class="bi bi-download"></i></span>
            <div>
                <h2 class="panel-title" id="agentInstallerTitle">Agent Installer</h2>
                <p>Download the Windows monitoring agent installer for deployment on IT devices.</p>
            </div>
            <a class="btn btn-primary" href="<?= $url('/agent/download') ?>"><i class="bi bi-download"></i> Download Agent</a>
        </section>
        <section class="settings-agent-download" aria-labelledby="collectorManagementTitle">
            <span class="module-icon"><i class="bi bi-router"></i></span>
            <div class="flex-grow-1">
                <h2 class="panel-title" id="collectorManagementTitle">Network Device Collector</h2>
                <p>Authorize an opted-in agent computer to collect network-device information for its office.</p>
                <?php if (!$collectors): ?>
                    <div class="alert alert-info mb-0">No collector computers are available. Install agent 1.3.6 and select “Make this device a collector computer” during setup.</div>
                <?php else: ?>
                    <form method="post" action="<?= $url('/settings/network-collectors') ?>" class="d-flex flex-wrap align-items-end gap-2" data-collector-settings-form>
                        <?= Csrf::field() ?>
                        <label>Office
                            <select name="office_id" class="form-select" required data-collector-office>
                                <option value="">Select office</option>
                                <?php foreach ($offices as $office): ?><option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Collector computer
                            <select name="computer_id" class="form-select" required disabled data-collector-computer>
                                <option value="">Select collector computer</option>
                                <?php foreach ($collectors as $collector): ?><option value="<?= (int)$collector['id'] ?>" data-office-id="<?= (int)$collector['office_id'] ?>"><?= e($collector['computer_name']) ?> (<?= $collector['enabled'] ? 'Enabled' : 'Disabled' ?>)</option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Authorization
                            <select name="enabled" class="form-select"><option value="0">Disabled</option><option value="1">Enabled</option></select>
                        </label>
                        <button class="btn btn-primary" type="submit">Save</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>
</section>
