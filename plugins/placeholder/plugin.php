<?php
declare(strict_types=1);

namespace Plugins\Placeholder;

use Core\Plugin;
use Plugins\Gql\Registry;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class PlaceholderPlugin extends Plugin
{
    public function register(): void
    {
        Registry::registerField('query', 'placeholder_text', function ($args, $context) {
            $res = $context->withCaller('placeholder')->gql('query($ns: String!, $where: [StoreFilter!]) { store_find(ns: $ns, where: $where) { id data } }', [
                'ns' => 'placeholder.settings',
                'where' => [['field' => 'key', 'op' => 'EQ', 'value' => 'welcome_message']]
            ]);
            $docs = $res['data']['store_find'] ?? [];
            if (!empty($docs)) {
                return $docs[0]['data']['text'] ?? 'Site under maintenance';
            }
            return 'Site under maintenance';
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'placeholder_set_text', function ($args, $context) {
            $text = \Core\Security::sanitizeString($args['text'] ?? '');
            $res = $context->withCaller('placeholder')->gql('query($ns: String!, $where: [StoreFilter!]) { store_find(ns: $ns, where: $where) { id data } }', [
                'ns' => 'placeholder.settings',
                'where' => [['field' => 'key', 'op' => 'EQ', 'value' => 'welcome_message']]
            ]);
            $docs = $res['data']['store_find'] ?? [];
            if (empty($docs)) {
                $context->withCaller('placeholder')->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
                    'ns' => 'placeholder.settings',
                    'data' => ['key' => 'welcome_message', 'text' => $text]
                ]);
            } else {
                $context->withCaller('placeholder')->gql('mutation($ns: String!, $id: ID!, $data: JSON!) { store_update(ns: $ns, id: $id, data: $data) }', [
                    'ns' => 'placeholder.settings',
                    'id' => $docs[0]['id'],
                    'data' => ['key' => 'welcome_message', 'text' => $text]
                ]);
            }
            return true;
        }, ['auth' => 'admin']);
    }
}
