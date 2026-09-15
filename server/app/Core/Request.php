<?php

class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }
        return '/' . trim($uri, '/');
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function json(int $maxBytes = 1048576): array
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            Response::json(['error' => 'Request payload is too large'], 413);
        }

        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > $maxBytes) {
            Response::json(['error' => 'Request payload is too large'], 413);
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (strcasecmp($name, 'Authorization') === 0) {
            return $_SERVER['Authorization']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? null) : null)
                ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);
        }
        return null;
    }

    public static function expectsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with(self::path(), '/api/');
    }
}
