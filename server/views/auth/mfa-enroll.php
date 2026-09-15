<link href="<?= $url('/static/css/login.css') ?>?v=mfa-required-<?= e((string)filemtime(__DIR__ . '/../../public/static/css/login.css')) ?>" rel="stylesheet">
<main class="login-page">
    <section class="login-card mfa-enrollment-card" aria-labelledby="mfaEnrollmentTitle" data-mfa-container>
        <header class="login-brand" aria-label="IT Asset Monitoring System">
            <img class="login-brand-image" src="<?= $url('/static/img/login-logo.png') ?>?v=<?= e((string)filemtime(__DIR__ . '/../../public/static/img/login-logo.png')) ?>" alt="IT Asset Monitoring System logo">
        </header>
        <div class="login-heading">
            <h1 id="mfaEnrollmentTitle">Secure your account</h1>
            <p>MFA is required when signing in with a username and password.</p>
        </div>
        <?php foreach ($flash as $message): ?>
            <?php $flashType = in_array($message['type'] ?? '', ['success', 'info', 'warning', 'danger'], true) ? $message['type'] : 'info'; ?>
            <div class="alert alert-<?= e($flashType) ?> login-alert" role="alert"><?= e($message['message'] ?? '') ?></div>
        <?php endforeach; ?>
        <div class="mfa-login-steps">
            <p><strong>1.</strong> Open your authenticator app.</p>
            <p><strong>2.</strong> Add an account and scan this QR code.</p>
            <div class="mfa-qr" data-mfa-qr data-mfa-uri="<?= e($mfaProvisioningUri) ?>" aria-label="Authenticator setup QR code"></div>
            <details><summary>Cannot scan the QR code?</summary><p>Enter this setup key manually:</p><code class="mfa-secret"><?= e($mfaEnrollment['secret']) ?></code></details>
            <p><strong>3.</strong> Enter the current six-digit code to finish setup.</p>
        </div>
        <form class="login-form mfa-enrollment-form" method="post" action="<?= $url('/login/mfa/setup') ?>">
            <?= Csrf::field() ?>
            <div class="login-field">
                <label for="mfaEnrollmentCode">Authenticator code</label>
                <div class="login-input-wrap">
                    <i class="bi bi-shield-lock login-field-icon" aria-hidden="true"></i>
                    <input id="mfaEnrollmentCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="Enter six-digit code" required autofocus>
                </div>
            </div>
            <button class="login-submit" type="submit">Enable MFA and sign in</button>
        </form>
        <form method="post" action="<?= $url('/login/mfa/cancel') ?>" class="mfa-cancel-login">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-link">Use another account</button>
        </form>
    </section>
</main>
