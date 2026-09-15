<?php

class Router
{
    private array $routes = [];
    private array $publicPaths = [
        '/login',
        '/login/google',
        '/login/google/callback',
        '/login/mfa',
        '/login/mfa/setup',
        '/login/mfa/cancel',
    ];
    private array $sessionExemptPrefixes = ['/api/agent/'];

    public function get(string $path, array $handler): void
    {
        $this->routes['GET'][$this->normalize($path)] = $handler;
    }

    public function post(string $path, array $handler): void
    {
        $this->routes['POST'][$this->normalize($path)] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $path = $this->normalize($path);
        $handler = $this->routes[$method][$path] ?? null;

        if (!$handler) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        if ($this->requiresSession($path) && !Auth::user()) {
            if (Request::expectsJson()) {
                Response::json(['error' => 'Unauthenticated'], 401);
            }
            Response::redirect('/login');
        }

        [$class, $action] = $handler;
        (new $class())->$action();
    }

    private function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }

    private function requiresSession(string $path): bool
    {
        if (in_array($path, $this->publicPaths, true)) {
            return false;
        }

        foreach ($this->sessionExemptPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
