<?php

class DashboardController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        [$where, $params] = ScopeService::assetWhere($user);
        $canFilterDashboard = ScopeService::isAdmin($user);
        $selectedDashboardOfficeId = 0;
        $selectedDashboardUserId = 0;
        $dashboardOffices = [];
        $dashboardUsers = [];
        if ($canFilterDashboard) {
            $dashboardOffices = $this->rows('SELECT id, name FROM offices ORDER BY name');
            $dashboardUsers = $this->rows(
                'SELECT users.id, users.name, users.office_id, roles.slug AS role_slug
                 FROM users
                 JOIN roles ON roles.id = users.role_id
                 WHERE users.status = \'active\'
                 ORDER BY users.name'
            );
            $selectedDashboardOfficeId = $this->selectedId('office_id', array_column($dashboardOffices, 'id'));
            $selectedDashboardUserId = $this->selectedId('user_id', array_column($dashboardUsers, 'id'));
            if ($selectedDashboardOfficeId > 0 && $selectedDashboardUserId > 0) {
                $selectedUserOfficeId = 0;
                $selectedUserCanSeeAllOffices = false;
                foreach ($dashboardUsers as $dashboardUser) {
                    if ((int)$dashboardUser['id'] === $selectedDashboardUserId) {
                        $selectedUserOfficeId = (int)($dashboardUser['office_id'] ?? 0);
                        $selectedUserCanSeeAllOffices = ($dashboardUser['role_slug'] ?? '') === 'admin' && $selectedUserOfficeId === 0;
                        break;
                    }
                }
                if (!$selectedUserCanSeeAllOffices && $selectedUserOfficeId !== $selectedDashboardOfficeId) {
                    $selectedDashboardUserId = 0;
                }
            }
            [$where, $params] = $this->dashboardAssetWhere($where, $params, $selectedDashboardOfficeId, $selectedDashboardUserId);
        }

        $app = require __DIR__ . '/../../config/app.php';
        $offlineAfter = (int)$app['offline_after_minutes'];
        [$activityPeriod, $activityPeriodLabel, $activityStart] = $this->activityPeriod();
        NetworkPrinterService::schema($this->db);
        $isNetworkPrinter = 'EXISTS (SELECT 1 FROM network_printer_links np WHERE np.computer_id = computers.id)';
        $managedDeviceCondition = '(agent_token_hash IS NOT NULL OR ' . $isNetworkPrinter . ')';
        $computerCondition = '(agent_token_hash IS NOT NULL AND NOT ' . $isNetworkPrinter . ')';
        $networkDeviceCondition = $isNetworkPrinter;
        $onlineCondition = '(' . NetworkPrinterService::onlineSql($offlineAfter) . ' AND (agent_token_hash IS NOT NULL OR ' . $isNetworkPrinter . '))';
        $notConnectedCondition = '((agent_token_hash IS NOT NULL OR ' . $isNetworkPrinter . ') AND NOT COALESCE(' . $onlineCondition . ',0))';
        $stats = [
            'total_computers' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$managedDeviceCondition}", $params),
            'computer_devices' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$computerCondition} AND {$onlineCondition} AND office_id IS NOT NULL", $params),
            'network_devices' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$networkDeviceCondition} AND {$onlineCondition} AND office_id IS NOT NULL", $params),
            'unassigned_devices' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$managedDeviceCondition} AND office_id IS NULL", $params),
            'online_computers' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$onlineCondition}", $params),
            'not_connected_computers' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$notConnectedCondition} AND office_id IS NOT NULL", $params),
        ];
        $weeklyStats = [
            'total_computers' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$managedDeviceCondition} AND created_at >= NOW() - INTERVAL 7 DAY", $params),
            'computer_devices' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$computerCondition} AND {$onlineCondition} AND office_id IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY", $params),
            'network_devices' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$networkDeviceCondition} AND {$onlineCondition} AND office_id IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY", $params),
            'unassigned_devices' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$managedDeviceCondition} AND office_id IS NULL AND created_at >= NOW() - INTERVAL 7 DAY", $params),
            'online_computers' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$onlineCondition} AND last_checkin_at >= NOW() - INTERVAL 7 DAY", $params),
            'not_connected_computers' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND {$notConnectedCondition} AND office_id IS NOT NULL AND COALESCE(agent_uninstalled_at, last_checkin_at, updated_at, created_at) >= NOW() - INTERVAL 7 DAY", $params),
        ];
        $devicesByOfficeChart = $this->rows(
            "SELECT
                COALESCE(offices.name, 'Unassigned') AS office,
                SUM(CASE
                    WHEN computers.is_offline_device = 0
                     AND {$onlineCondition}
                     AND NOT {$isNetworkPrinter}
                     AND computers.office_id IS NOT NULL
                    THEN 1 ELSE 0
                END) AS online_devices,
                SUM(CASE
                    WHEN {$networkDeviceCondition}
                     AND {$onlineCondition}
                     AND computers.office_id IS NOT NULL
                    THEN 1 ELSE 0
                END) AS network_devices,
                SUM(CASE
                    WHEN computers.is_offline_device = 0
                     AND {$notConnectedCondition}
                     AND computers.office_id IS NOT NULL
                    THEN 1 ELSE 0
                END) AS disconnected_devices,
                SUM(CASE WHEN computers.office_id IS NULL THEN 1 ELSE 0 END) AS unassigned_devices,
                COUNT(*) AS total_devices
             FROM computers
             LEFT JOIN offices ON offices.id = computers.office_id
             WHERE {$where} AND {$managedDeviceCondition}
             GROUP BY computers.office_id, offices.name
             ORDER BY total_devices DESC, office",
            $params
        );
        $moduleSummary = $this->moduleSummary($user, $where, $params, $selectedDashboardOfficeId);

        [$deviceWeekStart, $deviceMonthStart, $deviceYearStart] = $this->deviceUpdateStarts();
        $deviceActivityTotals = $this->rows(
            "SELECT
                SUM(CASE
                    WHEN COALESCE(updated_at, created_at) >= ?
                    THEN 1 ELSE 0
                END) AS weekly_updated,
                SUM(CASE
                    WHEN COALESCE(updated_at, created_at) >= ?
                     AND COALESCE(updated_at, created_at) < ?
                    THEN 1 ELSE 0
                END) AS monthly_updated,
                SUM(CASE
                    WHEN COALESCE(updated_at, created_at) >= ?
                     AND COALESCE(updated_at, created_at) < ?
                    THEN 1 ELSE 0
                END) AS yearly_updated
             FROM computers
             WHERE {$where}",
            array_merge([$deviceWeekStart, $deviceMonthStart, $deviceWeekStart, $deviceYearStart, $deviceMonthStart], $params)
        )[0] ?? [];
        $deviceUpdateActivity = [
            ['label' => 'Weekly Updated', 'total' => (int)($deviceActivityTotals['weekly_updated'] ?? 0)],
            ['label' => 'Monthly Updated', 'total' => (int)($deviceActivityTotals['monthly_updated'] ?? 0)],
            ['label' => 'Yearly Updated', 'total' => (int)($deviceActivityTotals['yearly_updated'] ?? 0)],
        ];
        [$officeWhere, $officeParams] = $this->officeWhere($user, $selectedDashboardOfficeId);
        $officeUpdateActivity = $this->rows(
            "SELECT offices.name AS office, COUNT(office_activity.office_id) AS total
             FROM offices
             LEFT JOIN (
                 SELECT COALESCE(
                     computers.office_id,
                     CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_logs.metadata, '$.office_id')) AS UNSIGNED)
                 ) AS office_id
                 FROM audit_logs
                 LEFT JOIN computers ON computers.id = audit_logs.entity_id
                 WHERE audit_logs.entity_type = 'device'
                   AND audit_logs.action IN ('created', 'updated', 'changed_assignment', 'assigned_office', 'unassigned_office')
                   AND audit_logs.created_at >= ?
                   AND {$where}
                   AND COALESCE(
                       computers.office_id,
                       CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_logs.metadata, '$.office_id')) AS UNSIGNED)
                   ) IS NOT NULL
                 UNION ALL
                 SELECT computers.office_id
                 FROM computers
                 WHERE {$where}
                   AND computers.office_id IS NOT NULL
                   AND computers.agent_token_hash IS NOT NULL
                   AND computers.agent_uninstalled_at IS NULL
                   AND computers.last_checkin_at >= ?
             ) AS office_activity
               ON office_activity.office_id = offices.id
             WHERE {$officeWhere}
             GROUP BY offices.id, offices.name
             ORDER BY total DESC, offices.name",
            array_merge([$activityStart], $params, [$activityStart], $params, $officeParams)
        );
        $this->view('dashboard/index', compact('stats', 'weeklyStats', 'devicesByOfficeChart', 'moduleSummary', 'deviceUpdateActivity', 'officeUpdateActivity', 'activityPeriod', 'activityPeriodLabel', 'canFilterDashboard', 'dashboardOffices', 'dashboardUsers', 'selectedDashboardOfficeId', 'selectedDashboardUserId') + [
            'title' => 'Dashboard',
            'subtitle' => 'Real-time overview of your IT assets',
        ]);
    }

    private function moduleSummary(array $user, string $where, array $params, int $selectedOfficeId): array
    {
        [$officeWhere, $officeParams] = $this->officeWhere($user, $selectedOfficeId);
        $usersWhere = "users.status = 'active'";
        $usersParams = [];
        if (ScopeService::isAdmin($user) && $selectedOfficeId > 0) {
            $usersWhere .= ' AND users.office_id = ?';
            $usersParams[] = $selectedOfficeId;
        } elseif (!ScopeService::isAdmin($user)) {
            $officeIds = ScopeService::officeIdsForUser($user);
            $usersWhere .= $officeIds ? ' AND users.office_id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')' : ' AND 1=0';
            $usersParams = $officeIds;
        }

        return [
            ['label' => 'Devices', 'total' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where}", $params), 'icon' => 'bi-pc-display', 'href' => '/device', 'permission' => 'assets.view'],
            ['label' => 'Unassigned', 'total' => $this->count("SELECT COUNT(*) FROM computers WHERE {$where} AND computers.office_id IS NULL", $params), 'icon' => 'bi-building-dash', 'href' => '/unassigned-devices', 'permission' => 'offices.manage'],
            ['label' => 'Tasks', 'total' => $this->count("SELECT COUNT(*) FROM update_assignments JOIN computers ON computers.id = update_assignments.computer_id WHERE update_assignments.status = 'pending' AND {$where}", $params), 'icon' => 'bi-check2-square', 'href' => '/task', 'permission' => 'assets.view'],
            ['label' => 'Offices', 'total' => $this->count("SELECT COUNT(*) FROM offices WHERE {$officeWhere}", $officeParams), 'icon' => 'bi-buildings', 'href' => '/offices', 'permission' => 'offices.manage'],
            ['label' => 'Employees', 'total' => $this->count("SELECT COUNT(*) FROM employees LEFT JOIN offices ON offices.id = employees.office_id WHERE {$officeWhere}", $officeParams), 'icon' => 'bi-person-vcard', 'href' => '/employees', 'permission' => 'employees.manage'],
            ['label' => 'Users', 'total' => $this->count("SELECT COUNT(*) FROM users WHERE {$usersWhere}", $usersParams), 'icon' => 'bi-people', 'href' => '/users', 'permission' => 'users.manage'],
            ['label' => 'Reports', 'total' => $this->count("SELECT COUNT(*) FROM audit_logs WHERE created_at >= NOW() - INTERVAL 14 DAY"), 'icon' => 'bi-file-earmark-bar-graph', 'href' => '/reports', 'permission' => 'reports.view'],
        ];
    }

    private function activityPeriod(): array
    {
        $periods = [
            'day' => ['This Day', 'today'],
            'week' => ['This Week', 'monday this week'],
            'month' => ['This Month', 'first day of this month'],
            'year' => ['This Year', 'first day of January this year'],
        ];
        $period = strtolower(trim((string)Request::input('period', 'week')));
        if (!array_key_exists($period, $periods)) {
            $period = 'week';
        }

        [$label, $dateText] = $periods[$period];
        $start = new DateTimeImmutable($dateText);
        return [$period, $label, $start->setTime(0, 0, 0)->format('Y-m-d H:i:s')];
    }

    private function deviceUpdateStarts(): array
    {
        $formatStart = static fn(string $dateText): string =>
            (new DateTimeImmutable($dateText))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        return [
            $formatStart('monday this week'),
            $formatStart('first day of this month'),
            $formatStart('first day of January this year'),
        ];
    }

    private function dashboardAssetWhere(string $where, array $params, int $officeId, int $userId): array
    {
        if ($officeId > 0) {
            $where .= ' AND computers.office_id = ?';
            $params[] = $officeId;
        }
        if ($userId > 0) {
            $where .= ' AND computers.added_by_user_id = ?';
            $params[] = $userId;
        }
        return [$where, $params];
    }

    private function selectedId(string $key, array $allowedIds): int
    {
        $id = (int)Request::input($key);
        return in_array($id, array_map('intval', $allowedIds), true) ? $id : 0;
    }

    private function officeWhere(array $user, int $selectedOfficeId = 0): array
    {
        if (ScopeService::isAdmin($user)) {
            if ($selectedOfficeId > 0) {
                return ['offices.id = ?', [$selectedOfficeId]];
            }
            return ['1=1', []];
        }

        $officeIds = ScopeService::officeIdsForUser($user);
        if ($officeIds) {
            return [
                'offices.id IN (' . implode(',', array_fill(0, count($officeIds), '?')) . ')',
                $officeIds,
            ];
        }

        return ['1=0', []];
    }

    private function count(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

}
