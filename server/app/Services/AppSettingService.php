<?php

class AppSettingService
{
    public static function get(string $key): ?string
    {
        $db = Database::connection();
        try {
            $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
        } catch (PDOException $exception) {
            if (!in_array($exception->getCode(), ['42P01', '42S02'], true)) {
                throw $exception;
            }
            self::ensureTable($db);
            $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
        }
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    public static function set(string $key, string $value): void
    {
        $db = Database::connection();
        self::ensureTable($db);
        $stmt = $db->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        $stmt->execute([$key, $value]);
    }

    public static function ensureTable(?PDO $db = null): void
    {
        $db ??= Database::connection();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS app_settings (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(120) NOT NULL UNIQUE,
                setting_value TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )'
        );
    }
}
