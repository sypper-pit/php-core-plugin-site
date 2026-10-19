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
        if (!extension_loaded('mbstring') || !extension_loaded('sodium')) {
            http_response_code(500);
            echo "Kernel Boot Error: PHP extensions 'mbstring' and 'sodium' are required.";
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
