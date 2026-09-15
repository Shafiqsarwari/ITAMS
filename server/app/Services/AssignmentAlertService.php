<?php

class AssignmentAlertService
{
    public static function summaryForUser(int $userId): array
    {
        if ($userId <= 0 || !self::assignmentsTableExists()) {
            return ['pending_count' => 0, 'has_unseen' => false];
        }

        $seenId = (int)(AppSettingService::get(self::seenSettingKey($userId)) ?? 0);
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS pending_count,
                COALESCE(MAX(CASE WHEN id > ? THEN 1 ELSE 0 END), 0) AS has_unseen
             FROM update_assignments
             WHERE (assigned_to_user_id = ? OR assigned_by_user_id = ?) AND status = \'pending\''
           );
        $stmt->execute([$seenId, $userId, $userId]);
        $summary = $stmt->fetch() ?: [];

        $deleteSummary = DeleteRequestService::pendingSummary($userId);
        $deleteSeenId = (int)(AppSettingService::get(self::deleteSeenSettingKey($userId)) ?? 0);
        return [
            'pending_count' => (int)($summary['pending_count'] ?? 0) + $deleteSummary['pending_count'],
            'has_unseen' => (bool)($summary['has_unseen'] ?? false) || $deleteSummary['latest_id'] > $deleteSeenId,
        ];
    }

    public static function markSeenForUser(int $userId): void
    {
        if ($userId <= 0 || !self::assignmentsTableExists()) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'SELECT MAX(id) FROM update_assignments WHERE assigned_to_user_id = ? OR assigned_by_user_id = ?'
        );
        $stmt->execute([$userId, $userId]);
        $latestAssignmentId = (int)$stmt->fetchColumn();
        if ($latestAssignmentId > 0) {
            AppSettingService::set(self::seenSettingKey($userId), (string)$latestAssignmentId);
        }
        $deleteSummary = DeleteRequestService::pendingSummary($userId);
        if ($deleteSummary['latest_id'] > 0) {
            AppSettingService::set(self::deleteSeenSettingKey($userId), (string)$deleteSummary['latest_id']);
        }
    }

    private static function assignmentsTableExists(): bool
    {
        static $exists;

        if ($exists !== null) {
            return $exists;
        }

        $exists = (bool)Database::connection()->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'update_assignments'"
        )->fetchColumn();

        return $exists;
    }

    private static function seenSettingKey(int $userId): string
    {
        return 'assignment_alert_seen_id_user_' . $userId;
    }

    private static function deleteSeenSettingKey(int $userId): string
    {
        return 'delete_request_alert_seen_id_user_' . $userId;
    }
}
