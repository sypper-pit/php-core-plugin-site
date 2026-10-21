<?php
declare(strict_types=1);

namespace Plugins\Auth;

use Core\Plugin;
use Plugins\Gql\Registry;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class AuthPlugin extends Plugin
{
    public function register(): void
    {
        Registry::registerField('query', 'me', function (array $args, $context) {
            return AuthHandler::me($context);
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'login', function (array $args, $context) {
            return AuthHandler::login($args, $context);
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'logout', function (array $args, $context) {
            return AuthHandler::logout($context);
        }, ['auth' => 'user']);
    }
}
