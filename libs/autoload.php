<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Core\\')) {
        $relative = str_replace('\\', '/', substr($class, 5));
        $file = APP_ROOT . '/core/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    } elseif (str_starts_with($class, 'SleekDB\\')) {
        $relative = str_replace('\\', '/', substr($class, 8));
        $file = APP_ROOT . '/libs/sleekdb/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    } elseif (str_starts_with($class, 'GraphQL\\')) {
        $relative = str_replace('\\', '/', substr($class, 8));
        $file = APP_ROOT . '/libs/graphql-php/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    } elseif (str_starts_with($class, 'Symfony\\Polyfill\\Mbstring\\')) {
        $relative = str_replace('\\', '/', substr($class, 25));
        $file = APP_ROOT . '/libs/polyfill-mbstring/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

if (!extension_loaded('mbstring') && file_exists(APP_ROOT . '/libs/polyfill-mbstring/bootstrap.php')) {
    require_once APP_ROOT . '/libs/polyfill-mbstring/bootstrap.php';
}
