<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'SleekDB\\')) {
        $relative = str_replace('\\', '/', substr($class, 8));
        $file = APP_ROOT . '/libs/sleekdb/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
