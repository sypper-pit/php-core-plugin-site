<?php
declare(strict_types=1);

namespace Plugins\Gql;

use Core\Plugin;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class GqlPlugin extends Plugin
{
    public function register(): void
    {
        // Gql is loaded by Kernel as core bus
    }
}
