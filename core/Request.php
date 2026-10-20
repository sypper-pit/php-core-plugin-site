<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Request
{
    public string $method;
    public string $uri;
    public array $headers;
    public array $params;
    public ?array $jsonBody = null;
    public string $rawBody = '';

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        $this->params = $_GET;

        $input = file_get_contents('php://input');
        if ($input !== false) {
            $this->rawBody = $input;
            if (isset($this->headers['content-type']) && str_contains($this->headers['content-type'], 'application/json')) {
                $decoded = json_decode($input, true);
                if (is_array($decoded)) {
                    $this->jsonBody = $decoded;
                }
            }
        }
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? null;
    }
}
