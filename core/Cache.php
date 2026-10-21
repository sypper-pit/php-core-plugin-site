<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Cache
{
    private static array $memory = [];

    public static function get(string $key): mixed
    {
        return self::$memory[$key] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$memory[$key] = $value;
    }

    public static function clear(): void
    {
        self::$memory = [];
    }
}
