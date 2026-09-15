<?php

class Controller
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    protected function view(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = __DIR__ . '/../../views/' . $view . '.php';
        require __DIR__ . '/../../views/layouts/app.php';
    }

    protected function requireAuth(): array
    {
        $user = Auth::user();
        if (!$user) {
            Response::redirect('/login');
        }
        return $user;
    }

    protected function requirePermission(string $permission): array
    {
        $user = $this->requireAuth();
        if (!Auth::can($permission)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        return $user;
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }
}
