<?php

class MfaController extends Controller
{
    public function challenge(): void
    {
        if (Auth::user()) {
            Response::redirect('/dashboard');
        }
        $user = Auth::pendingMfaUser();
        if (!$user) {
            $this->flash('warning', 'Your verification session expired. Please sign in again.');
            Response::redirect('/login');
        }
        if (Auth::pendingMfaPurpose() === 'enroll') {
            Response::redirect('/login/mfa/setup');
        }
        $this->view('auth/mfa', ['title' => 'Verify identity', 'pendingUser' => $user]);
    }

    public function verify(): void
    {
        Csrf::verify();
        $user = Auth::pendingMfaUser();
        if (!$user) {
            $this->flash('warning', 'Your verification session expired. Please sign in again.');
            Response::redirect('/login');
        }
        if (Auth::pendingMfaPurpose() !== 'challenge') {
            Response::redirect('/login/mfa/setup');
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimitService::allow('mfa-login:' . (int)$user['id'] . ':' . $ip, 10, 300)) {
            $this->flash('danger', 'Too many verification attempts. Please wait a few minutes and try again.');
            Response::redirect('/login/mfa');
        }
        $code = trim((string)Request::input('code', ''));
        if (!MfaService::verifyUserCode((int)$user['id'], $code, true)) {
            Auth::recordFailedLogin($user['email'] ?? null);
            $this->flash('danger', 'The verification or recovery code is incorrect or has already been used.');
            Response::redirect('/login/mfa');
        }
        Auth::completePendingMfa($user);
        Response::redirect('/dashboard');
    }

    public function cancelChallenge(): void
    {
        Csrf::verify();
        Auth::clearPendingMfa();
        Response::redirect('/login');
    }

    public function loginSetup(): void
    {
        if (Auth::user()) {
            Response::redirect('/dashboard');
        }
        $user = Auth::pendingMfaUser();
        if (!$user || Auth::pendingMfaPurpose() !== 'enroll') {
            $this->flash('warning', 'Your MFA setup session expired. Please sign in again.');
            Response::redirect('/login');
        }
        if (MfaService::isEnabled((int)$user['id'])) {
            Auth::beginMfaChallenge($user);
            Response::redirect('/login/mfa');
        }

        $enrollment = is_array($_SESSION['mfa_login_enrollment'] ?? null)
            ? $_SESSION['mfa_login_enrollment']
            : [];
        if ((int)($enrollment['user_id'] ?? 0) !== (int)$user['id']
            || time() - (int)($enrollment['started_at'] ?? 0) > 900
            || empty($enrollment['secret'])) {
            $enrollment = [
                'user_id' => (int)$user['id'],
                'secret' => MfaService::generateSecret(),
                'started_at' => time(),
            ];
            $_SESSION['mfa_login_enrollment'] = $enrollment;
        }

        $this->view('auth/mfa-enroll', [
            'title' => 'Set up MFA',
            'pendingUser' => $user,
            'mfaEnrollment' => $enrollment,
            'mfaProvisioningUri' => MfaService::provisioningUri(
                (string)$enrollment['secret'],
                (string)($user['email'] ?? $user['username'] ?? 'user')
            ),
        ]);
    }

    public function enableForLogin(): void
    {
        Csrf::verify();
        $user = Auth::pendingMfaUser();
        $enrollment = is_array($_SESSION['mfa_login_enrollment'] ?? null)
            ? $_SESSION['mfa_login_enrollment']
            : [];
        if (!$user || Auth::pendingMfaPurpose() !== 'enroll'
            || (int)($enrollment['user_id'] ?? 0) !== (int)($user['id'] ?? 0)
            || time() - (int)($enrollment['started_at'] ?? 0) > 900
            || empty($enrollment['secret'])) {
            Auth::clearPendingMfa();
            $this->flash('warning', 'Your MFA setup session expired. Please sign in again.');
            Response::redirect('/login');
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimitService::allow('mfa-required-enroll:' . (int)$user['id'] . ':' . $ip, 10, 300)) {
            $this->flash('danger', 'Too many verification attempts. Please wait a few minutes and try again.');
            Response::redirect('/login/mfa/setup');
        }
        if (!MfaService::verifySecret((string)$enrollment['secret'], (string)Request::input('code', ''))) {
            $this->flash('danger', 'Enter the current six-digit code from your authenticator app.');
            Response::redirect('/login/mfa/setup');
        }

        $recoveryCodes = MfaService::enable((int)$user['id'], (string)$enrollment['secret']);
        Auth::completePendingMfa($user);
        $_SESSION['mfa_login_recovery_codes'] = [
            'user_id' => (int)$user['id'],
            'codes' => $recoveryCodes,
        ];
        Audit::log('enabled', 'user_mfa', (int)$user['id'], ['source' => 'required_password_login']);
        Response::redirect('/login/mfa/recovery');
    }

    public function loginRecovery(): void
    {
        $user = $this->requireAuth();
        $recovery = is_array($_SESSION['mfa_login_recovery_codes'] ?? null)
            ? $_SESSION['mfa_login_recovery_codes']
            : [];
        if ((int)($recovery['user_id'] ?? 0) !== (int)$user['id'] || empty($recovery['codes'])) {
            unset($_SESSION['mfa_login_recovery_codes']);
            Response::redirect('/dashboard');
        }
        $this->view('auth/mfa-recovery', [
            'title' => 'Save recovery codes',
            'recoveryCodes' => $recovery['codes'],
            'standaloneAuth' => true,
        ]);
    }

    public function finishLoginEnrollment(): void
    {
        $this->requireAuth();
        Csrf::verify();
        unset($_SESSION['mfa_login_recovery_codes']);
        Response::redirect('/dashboard');
    }

    public function reset(): void
    {
        $actor = $this->requireAuth();
        Csrf::verify();
        if (!ScopeService::isAdmin($actor)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $targetId = (int)Request::input('user_id', 0);
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if (!$target) {
            $this->flash('danger', 'User not found or inactive.');
            Response::redirect('/users');
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimitService::allow('mfa-admin-reset:' . (int)$actor['id'] . ':' . $ip, 20, 300)) {
            $this->flash('danger', 'Too many MFA reset attempts. Please wait a few minutes and try again.');
            Response::redirect('/users?mfa=profile&mfa_user=' . $targetId);
        }

        MfaService::disable($targetId);
        if ($targetId === (int)$actor['id']) {
            unset($_SESSION['mfa_enrollment'], $_SESSION['mfa_recovery_codes']);
        }
        Audit::log('reset', 'user_mfa', $targetId, ['target_name' => (string)$target['name']]);
        $this->flash('success', 'Multi-factor authentication was reset for ' . (string)$target['name'] . '.');
        Response::redirect('/users?mfa=profile&mfa_user=' . $targetId);
    }

}
