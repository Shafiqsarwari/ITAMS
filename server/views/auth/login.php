<?php $loginValue = ''; ?>
<link href="<?= $url('/static/css/login.css') ?>?v=reference-4-<?= e((string)filemtime(__DIR__ . '/../../public/static/css/login.css')) ?>" rel="stylesheet">
<main class="login-page">
    <section class="login-card" aria-labelledby="loginTitle">
        <header class="login-brand" aria-label="IT Asset Monitoring System">
            <img class="login-brand-image" src="<?= $url('/static/img/login-logo.png') ?>?v=<?= e((string)filemtime(__DIR__ . '/../../public/static/img/login-logo.png')) ?>" alt="IT Asset Monitoring System logo">
        </header>
        <div class="login-heading">
            <h1 id="loginTitle">Welcome back</h1>
            <p>Sign in with your assigned account</p>
        </div>
        <?php foreach ($flash as $message): ?>
            <?php $flashType = in_array($message['type'] ?? '', ['success', 'info', 'warning', 'danger'], true) ? $message['type'] : 'info'; ?>
            <div class="alert alert-<?= e($flashType) ?> login-alert login-flash" role="alert" data-login-flash><?= e($message['message'] ?? '') ?></div>
        <?php endforeach; ?>
        <form class="login-form" method="post" action="<?= $url('/login') ?>">
            <?= Csrf::field() ?>
            <div class="login-field">
                <label for="loginIdentity">Email or username</label>
                <div class="login-input-wrap">
                    <i class="bi bi-person login-field-icon" aria-hidden="true"></i>
                    <input id="loginIdentity" name="login" type="text" value="<?= e($loginValue) ?>" placeholder="Enter your Email or username" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>
            </div>
            <div class="login-field">
                <label for="loginPassword">Password</label>
                <div class="login-input-wrap login-password-wrap">
                    <i class="bi bi-lock login-field-icon" aria-hidden="true"></i>
                    <input id="loginPassword" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                    <button class="login-password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle><i class="bi bi-eye-slash" aria-hidden="true"></i></button>
                </div>
            </div>
            <button class="login-submit" type="submit">Sign in</button>
        </form>
        <div class="login-social">
            <div class="login-divider" aria-hidden="true"><span>OR</span></div>
            <a class="login-google" href="<?= $url('/login/google') ?>">
                <svg class="login-google-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path fill="#4285f4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z"/>
                    <path fill="#34a853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"/>
                    <path fill="#fbbc05" d="M5.84 14.09A6.6 6.6 0 0 1 5.49 12c0-.73.13-1.43.35-2.09V7.07H2.18A11 11 0 0 0 1 12c0 1.78.43 3.45 1.18 4.93l2.85-2.22.81-.62Z"/>
                    <path fill="#ea4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A10.6 10.6 0 0 0 12 1a11 11 0 0 0-9.82 6.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53Z"/>
                </svg>
                <span>Sign in with Google</span>
            </a>
        </div>
    </section>
</main>
