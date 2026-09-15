<?php

class AgentDownloadController extends Controller
{
    public function download(): void
    {
        $this->requireAuth();

        $filename = 'ITAMSAgentSetup-1.3.17.exe';
        $installer = __DIR__ . '/../../../agent/installer/dist/' . $filename;
        if (!is_file($installer) || !is_readable($installer)) {
            http_response_code(404);
            echo 'Agent installer is not available.';
            exit;
        }

        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        set_time_limit(0);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)filesize($installer));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($installer);
        exit;
    }
}
