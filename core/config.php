<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

return [
    'allowed_hosts' => ['localhost', '127.0.0.1', 'localhost:8000', '127.0.0.1:8080', 'localhost:8080'],
    'max_body_size' => 4 * 1024 * 1024, // 4MB
    'admin_path_default' => '/panel-admin',
    'session_idle_timeout' => 1800, // 30 minutes
    'session_abs_timeout' => 28800, // 8 hours
    'vault_ttl' => 900, // 15 minutes
    'e2ee_max_file_size' => 3 * 1024 * 1024, // 3MB ciphertext limit
];
