<?php
declare(strict_types=1);

namespace Plugins\Gql;

use Core\Plugin;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/src/Engine.php';

class GqlPlugin extends Plugin
{
    public function register(): void
    {
        // Gql engine classes loaded
    }
}
