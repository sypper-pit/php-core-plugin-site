<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Files
{
    private static string $dataDir = APP_ROOT . '/data/';

    public static function readConfig(string $filename): ?array
    {
        $path = self::$dataDir . 'config/' . ltrim($filename, '/');
        if (!file_exists($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function writeConfig(string $filename, array $data): bool
    {
        $dir = self::$dataDir . 'config/';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . ltrim($filename, '/');
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::atomicWrite($path, $content);
    }

    public static function existsConfig(string $filename): bool
    {
        return file_exists(self::$dataDir . 'config/' . ltrim($filename, '/'));
    }

    public static function readTextConfig(string $filename): ?string
    {
        $path = self::$dataDir . 'config/' . ltrim($filename, '/');
        if (!file_exists($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content !== false ? trim($content) : null;
    }

    public static function writeTextConfig(string $filename, string $text): bool
    {
        $dir = self::$dataDir . 'config/';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . ltrim($filename, '/');
        return self::atomicWrite($path, $text);
    }

    public static function removeConfig(string $filename): bool
    {
        $path = self::$dataDir . 'config/' . ltrim($filename, '/');
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }

    public static function atomicWrite(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) !== false) {
            chmod($tmp, 0600);
            return rename($tmp, $path);
        }
        return false;
    }
}
