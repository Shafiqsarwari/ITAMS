<?php

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::user()) {
            Response::redirect('/dashboard');
        }
        if (Auth::pendingMfaUser()) {
            Response::redirect(Auth::pendingMfaPurpose() === 'enroll' ? '/login/mfa/setup' : '/login/mfa');
        }
        $app = require __DIR__ . '/../../config/app.php';
        $this->view('auth/login', [
            'title' => 'Login',
            'googleConfigured' => $this->googleConfigured($app),
        ]);
    }

    public function login(): void
    {
        if (Auth::user()) {
            Response::redirect('/dashboard');
        }

        Csrf::verify();
        $login = strtolower(trim((string)Request::input('login', '')));
        $password = (string)Request::input('password', '');

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimitService::allow('web-login:' . $ip, 10, 300)) {
            $this->flash('danger', 'Too many sign-in attempts. Please wait a few minutes and try again.');
            Response::redirect('/login');
        }

        $user = Auth::activeUserByLogin($login);
        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            Auth::recordFailedLogin(filter_var($login, FILTER_VALIDATE_EMAIL) ? $login : null);
            $this->flash('danger', 'The email/username or password is incorrect.');
            Response::redirect('/login');
        }

        $this->finishPasswordLogin($user);
    }

    public function googleRedirect(): void
    {
        $app = require __DIR__ . '/../../config/app.php';
        if (!$this->googleConfigured($app)) {
            $this->flash('warning', 'Google authentication is not configured yet.');
            Response::redirect('/login');
        }

        // Start OAuth from a fresh session identifier. This prevents a stale
        // session left open in the browser from losing its state on callback.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $state = bin2hex(random_bytes(32));
        $now = time();
        $states = is_array($_SESSION['google_oauth_states'] ?? null)
            ? $_SESSION['google_oauth_states']
            : [];
        $states = array_filter(
            $states,
            static fn($startedAt): bool => $now - (int)$startedAt <= 900
        );
        $states[$state] = $now;
        $_SESSION['google_oauth_states'] = array_slice($states, -5, null, true);
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_started_at']);
        $this->setGoogleStateCookie($app, $state, $now);
        $params = [
            'client_id' => $app['google_client_id'],
            'redirect_uri' => rtrim($app['base_url'], '/') . '/login/google/callback',
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ];
        session_write_close();
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    public function googleCallback(): void
    {
        $app = require __DIR__ . '/../../config/app.php';
        $state = (string)Request::input('state', '');
        $states = is_array($_SESSION['google_oauth_states'] ?? null)
            ? $_SESSION['google_oauth_states']
            : [];
        $started = (int)($states[$state] ?? 0);
        if ($started <= 0) {
            $expected = (string)($_SESSION['google_oauth_state'] ?? '');
            if ($expected !== '' && hash_equals($expected, $state)) {
                $started = (int)($_SESSION['google_oauth_started_at'] ?? 0);
            }
        }
        if ($started <= 0) {
            $started = $this->googleStateStartedFromCookie($app, $state);
        }
        $this->clearGoogleStateCookie($app);
        unset($states[$state]);
        $_SESSION['google_oauth_states'] = $states;
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_started_at']);
        if ($state === '' || $started <= 0 || time() - $started > 900 || $started > time() + 60) {
            $this->flash('danger', 'Google authentication expired. Please try again.');
            Response::redirect('/login');
        }
        $googleError = trim((string)Request::input('error', ''));
        if ($googleError !== '') {
            $this->flash('warning', $googleError === 'access_denied'
                ? 'Google sign-in was canceled.'
                : 'Google could not complete authentication. Please try again.');
            Response::redirect('/login');
        }
        $code = (string)Request::input('code', '');
        if ($code === '') {
            $this->flash('danger', 'Google did not return an authentication code.');
            Response::redirect('/login');
        }
        try {
            $token = $this->googleRequest('https://oauth2.googleapis.com/token', [
                'client_id' => $app['google_client_id'],
                'client_secret' => $app['google_client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => rtrim($app['base_url'], '/') . '/login/google/callback',
            ]);
            if (empty($token['access_token'])) {
                throw new RuntimeException('Google authentication did not return an access token.');
            }
            $profile = $this->googleRequest('https://www.googleapis.com/oauth2/v2/userinfo', [], [
                'Authorization: Bearer ' . $token['access_token'],
            ]);
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            $this->flash('danger', $exception->getMessage());
            Response::redirect('/login');
        }
        $email = strtolower(trim((string)($profile['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($profile['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            Auth::recordFailedLogin($email ?: null);
            $this->flash('danger', 'Your Google email could not be verified.');
            Response::redirect('/login');
        }
        $user = Auth::activeUserByEmail($email);
        if (!$user) {
            Auth::recordFailedLogin($email);
            $this->flash('danger', 'No active user is registered for this Google email.');
            Response::redirect('/login');
        }
        // Google has already authenticated the account. ITAMS MFA applies only
        // to users who enter their local username/email and password.
        $this->finishGoogleLogin($user, $email);
    }

    public function logout(): void
    {
        Csrf::verify();
        Auth::logout();
        Response::redirect('/login');
    }

    private function googleConfigured(array $app): bool
    {
        return trim((string)($app['google_client_id'] ?? '')) !== ''
            && trim((string)($app['google_client_secret'] ?? '')) !== '';
    }

    private function finishPasswordLogin(array $user): void
    {
        if (MfaService::isEnabled((int)$user['id'])) {
            Auth::beginMfaChallenge($user);
            Response::redirect('/login/mfa');
        }

        Auth::beginMfaEnrollment($user);
        Response::redirect('/login/mfa/setup');
    }

    private function finishGoogleLogin(array $user, string $email): void
    {
        Auth::loginUser($user, $email);
        Response::redirect('/dashboard');
    }

    private function setGoogleStateCookie(array $app, string $state, int $started): void
    {
        $payload = $state . '.' . $started;
        $signature = hash_hmac('sha256', $payload, (string)$app['google_client_secret']);
        $params = session_get_cookie_params();
        setcookie($this->googleStateCookieName($app), $payload . '.' . $signature, [
            'expires' => $started + 900,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function googleStateStartedFromCookie(array $app, string $state): int
    {
        $cookie = (string)($_COOKIE[$this->googleStateCookieName($app)] ?? '');
        $parts = explode('.', $cookie);
        if (count($parts) !== 3) {
            return 0;
        }

        [$cookieState, $started, $signature] = $parts;
        if ($state === '' || !ctype_digit($started) || !hash_equals($cookieState, $state)) {
            return 0;
        }
        $payload = $cookieState . '.' . $started;
        $expectedSignature = hash_hmac('sha256', $payload, (string)$app['google_client_secret']);
        return hash_equals($expectedSignature, $signature) ? (int)$started : 0;
    }

    private function clearGoogleStateCookie(array $app): void
    {
        $params = session_get_cookie_params();
        setcookie($this->googleStateCookieName($app), '', [
            'expires' => time() - 3600,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function googleStateCookieName(array $app): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($app['session_name'] ?? 'itams')) . '_google_oauth';
    }

    private function googleRequest(string $url, array $post = [], array $headers = []): array
    {
        $lastMessage = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Unable to start Google authentication.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            ]);
            if ($post) {
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post, '', '&', PHP_QUERY_RFC3986));
            }
            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);
            $data = json_decode((string)$body, true);
            if ($body !== false && $status >= 200 && $status < 300 && is_array($data)) {
                return $data;
            }

            $message = is_array($data)
                ? trim((string)($data['error_description'] ?? $data['error'] ?? ''))
                : '';
            $lastMessage = $message !== '' ? $message : $curlError;
            $retryable = $body === false || $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable || $attempt === 2) {
                break;
            }
            usleep(250000);
        }

        throw new RuntimeException('Google authentication request failed' . ($lastMessage !== '' ? ': ' . $lastMessage : '.'));
    }

}
