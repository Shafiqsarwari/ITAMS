<?php

class SettingController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->requireAllOfficeAdmin($user);
        $hasAgentRegistrationKeyOverride = AppSettingService::get('agent_registration_key') !== null;
        NetworkPrinterService::schema($this->db);
        $offlineAfter = max(1, (int)(require __DIR__ . '/../../config/app.php')['offline_after_minutes']);
        $offices = $this->db->query('SELECT id, name FROM offices ORDER BY name')->fetchAll();
        $collectors = $this->db->query('SELECT computers.id, computers.computer_name, computers.office_id, COALESCE(network_collectors.enabled, 0) AS enabled
            FROM network_collectors
            JOIN computers ON computers.id = network_collectors.computer_id
            WHERE computers.agent_token_hash IS NOT NULL AND computers.agent_uninstalled_at IS NULL
            AND computers.last_checkin_at >= NOW() - INTERVAL ' . $offlineAfter . ' MINUTE
            ORDER BY computers.computer_name')->fetchAll();
        $this->view('settings/index', [
            'title' => 'Settings',
            'subtitle' => 'Server and agent registration settings',
            'hasAgentRegistrationKeyOverride' => $hasAgentRegistrationKeyOverride,
            'offices' => $offices,
            'collectors' => $collectors,
        ]);
    }

    public function updateAgentRegistrationKey(): void
    {
        $user = $this->requireAuth();
        $this->requireAllOfficeAdmin($user);
        Csrf::verify();

        $key = (string)Request::input('agent_registration_key');
        $confirmation = (string)Request::input('agent_registration_key_confirmation');
        if (strlen($key) < 32) {
            $this->flash('danger', 'Use an agent registration key with at least 32 characters.');
            Response::redirect('/dashboard');
        }
        if (!hash_equals($key, $confirmation)) {
            $this->flash('danger', 'Agent registration key confirmation does not match.');
            Response::redirect('/dashboard');
        }

        AppSettingService::set('agent_registration_key', $key);

        Audit::log('updated_agent_registration_key', 'setting', null, ['setting_key' => 'agent_registration_key']);
        $this->flash('success', 'Agent registration key updated. New agent registrations must use the new key.');
        Response::redirect('/dashboard');
    }

    private function requireAllOfficeAdmin(array $user): void
    {
        if (!ScopeService::isAdmin($user) || !empty($user['office_id'])) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

}
