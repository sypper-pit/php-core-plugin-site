<?php
declare(strict_types=1);

namespace Plugins\Store;

use Core\Plugin;
use Plugins\Gql\Registry;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class StorePlugin extends Plugin
{
    public function register(): void
    {
        Registry::registerField('query', 'store_find', function (array $args, $context) {
            return Gateway::find($args, $context);
        }, ['auth' => 'public']);

        Registry::registerField('query', 'store_get', function (array $args, $context) {
            return Gateway::get($args, $context);
        }, ['auth' => 'public']);

        Registry::registerField('query', 'store_count', function (array $args, $context) {
            return Gateway::count($args, $context);
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'store_insert', function (array $args, $context) {
            return Gateway::insert($args, $context);
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'store_update', function (array $args, $context) {
            return Gateway::update($args, $context);
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'store_delete', function (array $args, $context) {
            return Gateway::delete($args, $context);
        }, ['auth' => 'public']);
    }
}
