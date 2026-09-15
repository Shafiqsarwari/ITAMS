<link href="<?= $url('/static/css/login.css') ?>?v=mfa-required-<?= e((string)filemtime(__DIR__ . '/../../public/static/css/login.css')) ?>" rel="stylesheet">
<main class="login-page">
    <section class="login-card mfa-recovery-card" aria-labelledby="mfaRecoveryTitle" data-mfa-container>
        <header class="login-brand" aria-label="IT Asset Monitoring System">
            <img class="login-brand-image" src="<?= $url('/static/img/login-logo.png') ?>?v=<?= e((string)filemtime(__DIR__ . '/../../public/static/img/login-logo.png')) ?>" alt="IT Asset Monitoring System logo">
        </header>
        <div class="login-heading">
            <h1 id="mfaRecoveryTitle">Save recovery codes</h1>
            <p>Store these one-time codes safely. Each code can be used once if your authenticator is unavailable.</p>
        </div>
        <div class="mfa-recovery-grid mfa-login-recovery-codes" data-mfa-recovery-codes>
            <?php foreach ($recoveryCodes as $recoveryCode): ?><code><?= e($recoveryCode) ?></code><?php endforeach; ?>
        </div>
        <button class="login-secondary-action" type="button" data-copy-recovery-codes><i class="bi bi-copy"></i> Copy recovery codes</button>
        <form method="post" action="<?= $url('/login/mfa/recovery') ?>" class="login-form mfa-recovery-finish">
            <?= Csrf::field() ?>
            <button class="login-submit" type="submit">Continue to dashboard</button>
        </form>
    </section>
</main>
