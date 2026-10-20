<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Kernel
{
    public static function boot(): void
    {
        $mbstringOk = extension_loaded('mbstring') || function_exists('mb_strlen');
        $sodiumOk = extension_loaded('sodium');

        if (!$mbstringOk || !$sodiumOk) {
            http_response_code(500);
            $missing = [];
            if (!$mbstringOk) $missing[] = "mbstring (extension or libs/polyfill-mbstring)";
            if (!$sodiumOk) $missing[] = "sodium (extension required)";
            echo "Kernel Boot Error: Missing required PHP extension(s)/capability: " . implode(', ', $missing) . ".";
            exit;
        }

        $config = require APP_ROOT . '/core/config.php';
        if (!Security::validateHost($config['allowed_hosts'])) {
            http_response_code(400);
            echo "Invalid Host Header";
            exit;
        }

        Security::setSecurityHeaders();
    }
}
