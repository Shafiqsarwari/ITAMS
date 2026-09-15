<?php $loginValue = ''; ?>
<link href="<?= $url('/static/css/login.css') ?>?v=mfa-<?= e((string)filemtime(__DIR__ . '/../../public/static/css/login.css')) ?>" rel="stylesheet">
<main class="login-page">
    <section class="login-card mfa-challenge-card" aria-labelledby="mfaChallengeTitle">
        <header class="login-brand" aria-label="IT Asset Monitoring System">
            <img class="login-brand-image" src="<?= $url('/static/img/login-logo.png') ?>?v=<?= e((string)filemtime(__DIR__ . '/../../public/static/img/login-logo.png')) ?>" alt="IT Asset Monitoring System logo">
        </header>
        <div class="login-heading">
            <h1 id="mfaChallengeTitle">Verification code</h1>
            <p>Enter the code from your authenticator app for <?= e($pendingUser['email'] ?? $pendingUser['name'] ?? 'your account') ?>.</p>
        </div>
        <?php foreach ($flash as $message): ?>
            <?php $flashType = in_array($message['type'] ?? '', ['success', 'info', 'warning', 'danger'], true) ? $message['type'] : 'info'; ?>
            <div class="alert alert-<?= e($flashType) ?> login-alert" role="alert"><?= e($message['message'] ?? '') ?></div>
        <?php endforeach; ?>
        <form class="login-form" method="post" action="<?= $url('/login/mfa') ?>">
            <?= Csrf::field() ?>
            <div class="login-field">
                <label for="mfaChallengeCode">Authenticator or recovery code</label>
                <div class="login-input-wrap">
                    <i class="bi bi-shield-lock login-field-icon" aria-hidden="true"></i>
                    <input id="mfaChallengeCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="Enter six-digit code" required autofocus>
                </div>
            </div>
            <button class="login-submit" type="submit">Verify and sign in</button>
        </form>
        <form method="post" action="<?= $url('/login/mfa/cancel') ?>" class="mfa-cancel-login">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-link">Use another account</button>
        </form>
    </section>
</main>
