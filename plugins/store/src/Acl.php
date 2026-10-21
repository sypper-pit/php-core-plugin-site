<?php
declare(strict_types=1);

namespace Plugins\Store;

use Core\Context;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Acl
{
    public static function checkAccess(string $ns, Context $context, string $op): bool
    {
        if (!preg_match('/^[a-z0-9_]{1,32}\.[a-z0-9_]{1,48}$/', $ns)) {
            return false;
        }

        $caller = $context->caller;
        $nsOwner = explode('.', $ns)[0];

        // Owner access always allowed
        if ($caller === $nsOwner) {
            return true;
        }

        // Core system caller has access
        if ($caller === 'core') {
            return true;
        }

        // Admin viewer via HTTP has store access
        if ($context->viewer->isAdmin()) {
            return true;
        }

        return false;
    }
}
