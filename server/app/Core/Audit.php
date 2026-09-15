<?php

class Audit
{
    public static function log(string $action, string $entity, ?int $entityId = null, array $metadata = []): void
    {
        $db = Database::connection();
        $userId = $_SESSION['user_id'] ?? null;
        $stmt = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $action,
            $entity,
            $entityId,
            json_encode($metadata, JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
