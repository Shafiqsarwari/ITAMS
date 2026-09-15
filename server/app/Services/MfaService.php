<?php

class MfaService
{
    private const PERIOD_SECONDS = 30;
    private const DIGITS = 6;
    private const RECOVERY_CODE_COUNT = 10;
    private static bool $schemaEnsured = false;

    public static function isEnabled(int $userId): bool
    {
        $row = self::userMfaRow($userId);
        return !empty($row['mfa_enabled_at']) && !empty($row['mfa_secret_encrypted']);
    }

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $email): string
    {
        $label = 'ITAMS:' . strtolower(trim($email));
        return 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => 'ITAMS',
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function verifySecret(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = intdiv($timestamp ?? time(), self::PERIOD_SECONDS);
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::totpAtStep($secret, $step + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function enable(int $userId, string $secret): array
    {
        self::ensureSchema();
        $recoveryCodes = self::generateRecoveryCodes();
        $recoveryHashes = array_map(
            static fn(string $code): string => password_hash(self::normalizeRecoveryCode($code), PASSWORD_DEFAULT),
            $recoveryCodes
        );
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET mfa_secret_encrypted = ?, mfa_recovery_codes = ?, mfa_enabled_at = NOW(),
                 mfa_last_used_step = NULL, updated_at = NOW()
             WHERE id = ? AND status = \'active\''
        );
        $stmt->execute([
            self::encryptSecret($secret),
            json_encode($recoveryHashes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('MFA could not be enabled for this account.');
        }
        return $recoveryCodes;
    }

    public static function disable(int $userId): void
    {
        self::ensureSchema();
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET mfa_secret_encrypted = NULL, mfa_recovery_codes = NULL,
                 mfa_enabled_at = NULL, mfa_last_used_step = NULL, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    public static function verifyUserCode(int $userId, string $code, bool $allowRecovery = true): bool
    {
        $row = self::userMfaRow($userId);
        if (empty($row['mfa_enabled_at']) || empty($row['mfa_secret_encrypted'])) {
            return false;
        }

        $numericCode = preg_replace('/\s+/', '', trim($code));
        if (preg_match('/^\d{6}$/', $numericCode)) {
            $secret = self::decryptSecret((string)$row['mfa_secret_encrypted']);
            $currentStep = intdiv(time(), self::PERIOD_SECONDS);
            foreach ([-1, 0, 1] as $offset) {
                $matchedStep = $currentStep + $offset;
                if (!hash_equals(self::totpAtStep($secret, $matchedStep), $numericCode)) {
                    continue;
                }
                $update = Database::connection()->prepare(
                    'UPDATE users SET mfa_last_used_step = ?
                     WHERE id = ? AND mfa_enabled_at IS NOT NULL
                       AND (mfa_last_used_step IS NULL OR mfa_last_used_step < ?)'
                );
                $update->execute([$matchedStep, $userId, $matchedStep]);
                return $update->rowCount() === 1;
            }
        }

        return $allowRecovery && self::consumeRecoveryCode($userId, $code);
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $db = Database::connection();
        $columns = [
            'mfa_secret_encrypted' => 'TEXT NULL',
            'mfa_recovery_codes' => 'TEXT NULL',
            'mfa_enabled_at' => 'TIMESTAMP NULL',
            'mfa_last_used_step' => 'BIGINT NULL',
        ];
        $check = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'users\' AND COLUMN_NAME = ?'
        );
        foreach ($columns as $name => $definition) {
            $check->execute([$name]);
            if ((int)$check->fetchColumn() === 0) {
                $db->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
            }
        }
        self::$schemaEnsured = true;
    }

    private static function userMfaRow(int $userId): array
    {
        $db = Database::connection();
        try {
            $stmt = $db->prepare(
                'SELECT id, mfa_secret_encrypted, mfa_recovery_codes, mfa_enabled_at, mfa_last_used_step
                 FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
        } catch (PDOException $exception) {
            self::ensureSchema();
            $stmt = $db->prepare(
                'SELECT id, mfa_secret_encrypted, mfa_recovery_codes, mfa_enabled_at, mfa_last_used_step
                 FROM users WHERE id = ?'
            );
            $stmt->execute([$userId]);
        }
        return $stmt->fetch() ?: [];
    }

    private static function consumeRecoveryCode(int $userId, string $candidate): bool
    {
        $candidate = self::normalizeRecoveryCode($candidate);
        if (!preg_match('/^[A-Z2-9]{8}$/', $candidate)) {
            return false;
        }

        $db = Database::connection();
        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare('SELECT mfa_recovery_codes FROM users WHERE id = ? AND mfa_enabled_at IS NOT NULL FOR UPDATE');
            $stmt->execute([$userId]);
            $hashes = json_decode((string)$stmt->fetchColumn(), true);
            if (!is_array($hashes)) {
                if ($startedTransaction) $db->rollBack();
                return false;
            }
            foreach ($hashes as $index => $hash) {
                if (!is_string($hash) || !password_verify($candidate, $hash)) {
                    continue;
                }
                unset($hashes[$index]);
                $update = $db->prepare('UPDATE users SET mfa_recovery_codes = ?, updated_at = NOW() WHERE id = ?');
                $update->execute([json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $userId]);
                if ($startedTransaction) $db->commit();
                return true;
            }
            if ($startedTransaction) $db->rollBack();
            return false;
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    private static function generateRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
        }
        return $codes;
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)));
    }

    private static function totpAtStep(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $result = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $result;
    }

    private static function base32Decode(string $value): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';
        $result = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $value))) as $character) {
            if (!isset($alphabet[$character])) {
                throw new RuntimeException('Invalid MFA secret.');
            }
            $bits .= str_pad(decbin($alphabet[$character]), 5, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $result .= chr(bindec($chunk));
        }
        return $result;
    }

    private static function encryptSecret(string $secret): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('MFA encryption failed.');
        }
        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decryptSecret(string $encrypted): string
    {
        if (!str_starts_with($encrypted, 'v1:')) {
            throw new RuntimeException('Unsupported MFA secret format.');
        }
        $payload = base64_decode(substr($encrypted, 3), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('Invalid MFA secret data.');
        }
        $secret = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA,
            substr($payload, 0, 12), substr($payload, 12, 16));
        if ($secret === false) {
            throw new RuntimeException('MFA secret could not be decrypted.');
        }
        return $secret;
    }

    private static function encryptionKey(): string
    {
        $configuredPath = trim((string)getenv('MFA_KEY_PATH'));
        $path = $configuredPath !== '' ? $configuredPath : dirname(__DIR__, 2) . '/storage/mfa.key';
        if (!is_file($path)) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('MFA key storage is unavailable.');
            }
            $encoded = base64_encode(random_bytes(32));
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fwrite($handle, $encoded);
                fflush($handle);
                fclose($handle);
                @chmod($path, 0600);
            }
        }
        $encodedKey = '';
        for ($attempt = 0; $attempt < 5 && $encodedKey === ''; $attempt++) {
            $encodedKey = trim((string)@file_get_contents($path));
            if ($encodedKey === '') usleep(20000);
        }
        $decoded = base64_decode($encodedKey, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('MFA encryption key is unavailable.');
        }
        return $decoded;
    }
}
