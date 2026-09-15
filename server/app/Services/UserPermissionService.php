<?php

class UserPermissionService
{
    private const MANAGED_PERMISSIONS = [
        'assets.delete' => [
            'name' => 'Delete Devices',
            'description' => 'Delete devices that are inside the user\'s permitted office scope.',
            'icon' => 'bi-pc-display',
        ],
        'employees.delete' => [
            'name' => 'Delete Employees',
            'description' => 'Delete employee records that are inside the user\'s permitted office scope.',
            'icon' => 'bi-person-vcard',
        ],
        'offices.delete' => [
            'name' => 'Delete Offices',
            'description' => 'Delete child offices that are inside the user\'s permitted office tree.',
            'icon' => 'bi-building',
        ],
    ];

    private static bool $schemaEnsured = false;

    public static function managedPermissions(): array
    {
        return self::MANAGED_PERMISSIONS;
    }

    public static function isManagedPermission(string $slug): bool
    {
        return array_key_exists($slug, self::MANAGED_PERMISSIONS);
    }

    public static function hasPermission(int $userId, string $slug): bool
    {
        if ($userId <= 0 || !self::isManagedPermission($slug)) {
            return false;
        }

        self::ensureSchema();
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM user_permissions
             JOIN permissions ON permissions.id = user_permissions.permission_id
             WHERE user_permissions.user_id = ? AND permissions.slug = ?'
        );
        $stmt->execute([$userId, $slug]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function grantedPermissions(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        self::ensureSchema();
        $slugs = array_keys(self::MANAGED_PERMISSIONS);
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT permissions.slug
             FROM user_permissions
             JOIN permissions ON permissions.id = user_permissions.permission_id
             WHERE user_permissions.user_id = ? AND permissions.slug IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$userId], $slugs));
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function replacePermissions(int $userId, array $slugs, int $grantedByUserId): void
    {
        self::ensureSchema();
        $allowed = array_keys(self::MANAGED_PERMISSIONS);
        $selected = array_values(array_unique(array_intersect(array_map('strval', $slugs), $allowed)));
        $db = Database::connection();
        $startedTransaction = !$db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $delete = $db->prepare(
                "DELETE user_permissions FROM user_permissions
                 JOIN permissions ON permissions.id = user_permissions.permission_id
                 WHERE user_permissions.user_id = ? AND permissions.slug IN ({$placeholders})"
            );
            $delete->execute(array_merge([$userId], $allowed));

            if ($selected) {
                $permissionQuery = $db->prepare(
                    'SELECT id, slug FROM permissions WHERE slug IN (' . implode(',', array_fill(0, count($selected), '?')) . ')'
                );
                $permissionQuery->execute($selected);
                $insert = $db->prepare(
                    'INSERT INTO user_permissions (user_id, permission_id, granted_by_user_id, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())'
                );
                foreach ($permissionQuery->fetchAll() as $permission) {
                    $insert->execute([$userId, (int)$permission['id'], $grantedByUserId]);
                }
            }

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $db = Database::connection();
        $permission = $db->prepare(
            'INSERT INTO permissions (name, slug, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = NOW()'
        );
        foreach (self::MANAGED_PERMISSIONS as $slug => $details) {
            $permission->execute([$details['name'], $slug]);
        }

        $db->exec(
            'CREATE TABLE IF NOT EXISTS user_permissions (
                user_id INT NOT NULL,
                permission_id INT NOT NULL,
                granted_by_user_id INT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                PRIMARY KEY (user_id, permission_id),
                CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_permissions_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB'
        );
        self::$schemaEnsured = true;
    }
}
