<?php

class ScopeService
{
    public static function isAdmin(array $user): bool
    {
        if (($user['role_slug'] ?? '') === 'super-admin') {
            return true;
        }

        return ($user['role_slug'] ?? '') === 'admin' && empty($user['office_id']);
    }

    public static function assetWhere(array $user, string $alias = 'computers'): array
    {
        if (self::isAdmin($user)) {
            return ['1=1', []];
        }

        if (!empty($user['office_id'])) {
            $officeIds = self::officeIdsForUser($user);
            if ($officeIds) {
                return ["{$alias}.office_id IN (" . implode(',', array_fill(0, count($officeIds), '?')) . ")", $officeIds];
            }
        }

        return ['1=0', []];
    }

    public static function canManageOffice(array $user, int $officeId): bool
    {
        if ($officeId <= 0) {
            return false;
        }

        if (self::isAdmin($user)) {
            return true;
        }

        return in_array($officeId, self::officeIdsForUser($user), true);
    }

    public static function officeIdsForUser(array $user): array
    {
        if (self::isAdmin($user)) {
            return [];
        }

        $officeId = (int)($user['office_id'] ?? 0);
        if ($officeId <= 0) {
            return [];
        }

        return self::descendantOfficeIds($officeId);
    }

    public static function descendantOfficeIds(int $officeId): array
    {
        if ($officeId <= 0) {
            return [];
        }

        $db = Database::connection();
        self::ensureOfficeHierarchyColumn($db);
        $rows = $db->query('SELECT id, parent_id FROM offices')->fetchAll();
        $children = [];
        foreach ($rows as $row) {
            $parentId = (int)($row['parent_id'] ?? 0);
            if ($parentId > 0) {
                $children[$parentId][] = (int)$row['id'];
            }
        }

        $result = [];
        $queue = [$officeId];
        while ($queue) {
            $current = array_shift($queue);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($children[$current] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $result;
    }

    public static function officeLabel(?int $id): string
    {
        if (!$id) {
            return 'All Offices';
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT name FROM offices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: 'Unassigned Office';
    }

    private static function ensureOfficeHierarchyColumn(PDO $db): void
    {
        // The schema and migrations provision this column.
    }
}
