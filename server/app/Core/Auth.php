<?php

class Auth
{
    private const ONLINE_WINDOW_SECONDS = 300;
    private static ?int $presenceTouchedForUserId = null;

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $now = time();
        $app = require __DIR__ . '/../../config/app.php';
        $authenticatedAt = (int)($_SESSION['authenticated_at'] ?? 0);
        $lastActivityAt = (int)($_SESSION['last_activity_at'] ?? $authenticatedAt);
        if ($authenticatedAt <= 0
            || $now - $authenticatedAt > (int)$app['session_absolute_timeout_seconds']
            || $now - $lastActivityAt > (int)$app['session_idle_timeout_seconds']
        ) {
            self::logout($lastActivityAt > 0 ? $lastActivityAt : $now);
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT users.*, roles.name AS role_name, roles.slug AS role_slug
             FROM users JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.status = 'active'"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id'], $_SESSION['authenticated_at'], $_SESSION['last_activity_at']);
            return null;
        }

        $_SESSION['last_activity_at'] = $now;
        self::touchPresence((int)$user['id']);
        $user['last_seen_at'] = date('Y-m-d H:i:s', $now);

        return $user;
    }

    public static function activeUserByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT users.*, roles.slug AS role_slug FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.email = ? AND users.status = 'active'"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function activeUserByUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $username)) {
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT users.*, roles.slug AS role_slug FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.username = ? AND users.status = 'active'"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function activeUserByLogin(string $login): ?array
    {
        $login = strtolower(trim($login));
        return filter_var($login, FILTER_VALIDATE_EMAIL)
            ? self::activeUserByEmail($login)
            : self::activeUserByUsername($login);
    }

    public static function loginUser(array $user, ?string $email = null): void
    {
        $db = Database::connection();
        session_regenerate_id(true);
        self::clearPendingMfa();
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = $_SESSION['authenticated_at'];
        self::$presenceTouchedForUserId = null;
        $update = $db->prepare('UPDATE users SET last_login_at = NOW(), last_seen_at = NOW(), logged_out_at = NULL WHERE id = ?');
        $update->execute([(int)$user['id']]);
        self::$presenceTouchedForUserId = (int)$user['id'];
        self::logLogin((int)$user['id'], true, $email ?: ($user['email'] ?? null));
    }

    public static function beginMfaChallenge(array $user, ?string $email = null): void
    {
        self::beginPendingMfa($user, $email, 'challenge');
    }

    public static function beginMfaEnrollment(array $user, ?string $email = null): void
    {
        self::beginPendingMfa($user, $email, 'enroll');
    }

    private static function beginPendingMfa(array $user, ?string $email, string $purpose): void
    {
        session_regenerate_id(true);
        unset($_SESSION['user_id'], $_SESSION['authenticated_at'], $_SESSION['last_activity_at']);
        unset($_SESSION['mfa_login_enrollment'], $_SESSION['mfa_login_recovery_codes']);
        $_SESSION['pending_mfa'] = [
            'user_id' => (int)$user['id'],
            'email' => $email ?: ($user['email'] ?? null),
            'purpose' => $purpose,
            'started_at' => time(),
        ];
    }

    public static function pendingMfaPurpose(): ?string
    {
        if (!self::pendingMfaUser()) {
            return null;
        }
        $purpose = (string)($_SESSION['pending_mfa']['purpose'] ?? 'challenge');
        return in_array($purpose, ['challenge', 'enroll'], true) ? $purpose : 'challenge';
    }

    public static function pendingMfaUser(): ?array
    {
        $pending = is_array($_SESSION['pending_mfa'] ?? null) ? $_SESSION['pending_mfa'] : [];
        $userId = (int)($pending['user_id'] ?? 0);
        $startedAt = (int)($pending['started_at'] ?? 0);
        if ($userId <= 0 || $startedAt <= 0 || time() - $startedAt > 300 || $startedAt > time() + 60) {
            self::clearPendingMfa();
            return null;
        }
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT users.*, roles.slug AS role_slug FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.status = 'active'"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function completePendingMfa(array $user): void
    {
        $pending = is_array($_SESSION['pending_mfa'] ?? null) ? $_SESSION['pending_mfa'] : [];
        $email = $pending['email'] ?? ($user['email'] ?? null);
        self::loginUser($user, is_string($email) ? $email : null);
    }

    public static function clearPendingMfa(): void
    {
        unset($_SESSION['pending_mfa'], $_SESSION['mfa_login_enrollment']);
    }

    public static function recordFailedLogin(?string $email = null): void
    {
        self::logLogin(null, false, $email);
    }

    public static function logout(?int $lastSeenAt = null): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            self::markOffline($userId, $lastSeenAt);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function onlineWindowSeconds(): int
    {
        return self::ONLINE_WINDOW_SECONDS;
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }
        if (($user['role_slug'] ?? '') === 'super-admin') {
            return true;
        }
        if (UserPermissionService::isManagedPermission($permission)) {
            if (ScopeService::isAdmin($user)) {
                return true;
            }
            return UserPermissionService::hasPermission((int)$user['id'], $permission);
        }
        if (($user['role_slug'] ?? '') === 'user' && in_array($permission, ['users.manage', 'offices.manage'], true)) {
            return false;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM role_permissions
             JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE role_permissions.role_id = ? AND permissions.slug = ?'
        );
        $stmt->execute([$user['role_id'], $permission]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function logLogin(?int $userId, bool $success, ?string $email = null): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO login_logs (user_id, email, ip_address, user_agent, success, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $email,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $success ? 1 : 0,
        ]);
    }

    private static function touchPresence(int $userId): void
    {
        if ($userId <= 0 || self::$presenceTouchedForUserId === $userId) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?');
        $stmt->execute([$userId]);
        self::$presenceTouchedForUserId = $userId;
    }

    private static function markOffline(int $userId, ?int $lastSeenAt = null): void
    {
        $db = Database::connection();
        if ($lastSeenAt !== null && $lastSeenAt > 0) {
            $stmt = $db->prepare("UPDATE users SET last_seen_at = to_timestamp(?), logged_out_at = NOW() WHERE id = ?");
            $stmt->execute([$lastSeenAt, $userId]);
        } else {
            $stmt = $db->prepare('UPDATE users SET last_seen_at = NOW(), logged_out_at = NOW() WHERE id = ?');
            $stmt->execute([$userId]);
        }
        if (self::$presenceTouchedForUserId === $userId) {
            self::$presenceTouchedForUserId = null;
        }
    }
}
