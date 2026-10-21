<?php
declare(strict_types=1);

namespace SleekDB\Classes;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class IoHelper
{
    public static function checkFileSystem(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
    }

    public static function writeContent(string $path, string $content): bool
    {
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) !== false) {
            chmod($tmp, 0600);
            return rename($tmp, $path);
        }
        return false;
    }
}
