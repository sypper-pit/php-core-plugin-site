<?php
declare(strict_types=1);

namespace Plugins\Admin;

use Core\Plugin;
use Core\Vault;
use Core\E2ee;
use Plugins\Gql\Registry;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class AdminPlugin extends Plugin
{
    public function register(): void
    {
        // Vault Mutations & Queries
        Registry::registerField('query', 'vault_status', function ($args, $context) {
            return ['unlocked' => Vault::isUnlocked()];
        }, ['auth' => 'admin']);

        Registry::registerField('mutation', 'vault_unlock', function ($args, $context) {
            return Vault::unlock($context, $args['masterPassword'] ?? '');
        }, ['auth' => 'public']);

        Registry::registerField('mutation', 'vault_lock', function ($args, $context) {
            Vault::lock();
            return true;
        }, ['auth' => 'admin']);

        Registry::registerField('mutation', 'vault_put', function ($args, $context) {
            $name = \Core\Security::sanitizeString($args['name'] ?? '');
            $mime = $args['mime'] ?? 'text/plain';
            $enc = Vault::encrypt($args['data'] ?? '');

            return $context->withCaller('admin')->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
                'ns' => 'core.vault_files',
                'data' => [
                    'name' => $name,
                    'mime' => $mime,
                    'size' => strlen($args['data'] ?? ''),
                    'created' => date('c'),
                    'enc' => $enc
                ]
            ])['data']['store_insert'];
        }, ['auth' => 'admin']);

        Registry::registerField('query', 'vault_list', function ($args, $context) {
            $res = $context->withCaller('admin')->gql('query($ns: String!) { store_find(ns: $ns) { id data created } }', ['ns' => 'core.vault_files']);
            $files = [];
            foreach ($res['data']['store_find'] ?? [] as $f) {
                $files[] = [
                    'id' => $f['id'],
                    'name' => $f['data']['name'],
                    'mime' => $f['data']['mime'],
                    'size' => $f['data']['size'],
                    'created' => $f['data']['created']
                ];
            }
            return $files;
        }, ['auth' => 'admin']);

        Registry::registerField('query', 'vault_get', function ($args, $context) {
            $res = $context->withCaller('admin')->gql('query($ns: String!, $id: ID!) { store_get(ns: $ns, id: $id) { id data created } }', [
                'ns' => 'core.vault_files',
                'id' => $args['id']
            ]);
            $doc = $res['data']['store_get'] ?? null;
            if (!$doc) return null;
            $decrypted = Vault::decrypt($doc['data']['enc']);
            return [
                'id' => $doc['id'],
                'name' => $doc['data']['name'],
                'mime' => $doc['data']['mime'],
                'size' => $doc['data']['size'],
                'created' => $doc['data']['created'],
                'data' => $decrypted
            ];
        }, ['auth' => 'admin']);

        Registry::registerField('mutation', 'vault_delete', function ($args, $context) {
            return $context->withCaller('admin')->gql('mutation($ns: String!, $id: ID!) { store_delete(ns: $ns, id: $id) }', [
                'ns' => 'core.vault_files', 'id' => $args['id']
            ])['data']['store_delete'];
        }, ['auth' => 'admin']);

        // E2EE Server Management
        Registry::registerField('mutation', 'e2ee_init', function ($args, $context) {
            $env = $args['envelope'] ?? [];
            if (!E2ee::validateEnvelope($env)) {
                throw new \InvalidArgumentException('Invalid E2EE envelope structure');
            }
            \Core\Files::writeConfig('e2ee-envelope.json', $env);
            return true;
        }, ['auth' => 'public']);

        Registry::registerField('query', 'e2ee_status', function ($args, $context) {
            return [
                'configured' => \Core\Files::existsConfig('e2ee-envelope.json'),
                'envelope' => \Core\Files::readConfig('e2ee-envelope.json')
            ];
        }, ['auth' => 'admin']);

        Registry::registerField('mutation', 'e2ee_put', function ($args, $context) {
            $file = $args['file'] ?? [];
            if (!E2ee::validateFileInput($file)) {
                throw new \InvalidArgumentException('Invalid E2EE file structure');
            }
            return $context->withCaller('admin')->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
                'ns' => 'core.e2ee_files',
                'data' => $file
            ])['data']['store_insert'];
        }, ['auth' => 'admin']);

        Registry::registerField('query', 'e2ee_list', function ($args, $context) {
            $res = $context->withCaller('admin')->gql('query($ns: String!) { store_find(ns: $ns) { id data created } }', ['ns' => 'core.e2ee_files']);
            $out = [];
            foreach ($res['data']['store_find'] ?? [] as $item) {
                $out[] = [
                    'id' => $item['id'],
                    'created' => $item['created'],
                    'v' => $item['data']['v'] ?? 1
                ];
            }
            return $out;
        }, ['auth' => 'admin']);

        Registry::registerField('query', 'e2ee_get', function ($args, $context) {
            $res = $context->withCaller('admin')->gql('query($ns: String!, $id: ID!) { store_get(ns: $ns, id: $id) { id data created } }', [
                'ns' => 'core.e2ee_files',
                'id' => $args['id']
            ]);
            $doc = $res['data']['store_get'] ?? null;
            if (!$doc) return null;
            $data = $doc['data'];
            $data['id'] = $doc['id'];
            return $data;
        }, ['auth' => 'admin']);

        Registry::registerField('mutation', 'e2ee_delete', function ($args, $context) {
            return $context->withCaller('admin')->gql('mutation($ns: String!, $id: ID!) { store_delete(ns: $ns, id: $id) }', [
                'ns' => 'core.e2ee_files', 'id' => $args['id']
            ])['data']['store_delete'];
        }, ['auth' => 'admin']);

        // Plugin Management
        Registry::registerField('query', 'admin_plugins', function ($args, $context) {
            $pluginsDir = APP_ROOT . '/plugins';
            $enabled = \Core\Files::readConfig('plugins-enabled.json') ?? ['gql', 'store', 'auth', 'admin', 'placeholder'];
            $items = [];

            if (is_dir($pluginsDir)) {
                $dirs = scandir($pluginsDir);
                foreach ($dirs as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("{$pluginsDir}/{$d}")) continue;
                    $manifestFile = "{$pluginsDir}/{$d}/plugin.json";
                    if (file_exists($manifestFile)) {
                        $meta = json_decode((string)file_get_contents($manifestFile), true) ?: [];
                        $id = $meta['id'] ?? $d;
                        $items[] = [
                            'id' => $id,
                            'name' => $meta['name'] ?? $id,
                            'version' => $meta['version'] ?? '1.0.0',
                            'description' => $meta['description'] ?? '',
                            'author' => $meta['author'] ?? '',
                            'enabled' => in_array($id, $enabled, true),
                            'isSystem' => in_array($id, ['gql', 'store', 'auth', 'admin'], true)
                        ];
                    }
                }
            }
            return $items;
        }, ['auth' => 'admin']);

        Registry::registerField('mutation', 'admin_toggle_plugin', function ($args, $context) {
            $id = strtolower($args['id'] ?? '');
            $enable = (bool)($args['enable'] ?? false);

            if (in_array($id, ['gql', 'store', 'auth', 'admin'], true) && !$enable) {
                throw new \InvalidArgumentException("Cannot disable system core plugin '{$id}'");
            }

            $enabled = \Core\Files::readConfig('plugins-enabled.json') ?? ['gql', 'store', 'auth', 'admin', 'placeholder'];
            if ($enable) {
                if (!in_array($id, $enabled, true)) {
                    $enabled[] = $id;
                }
            } else {
                $enabled = array_values(array_filter($enabled, fn($item) => $item !== $id));
            }

            \Core\Files::writeConfig('plugins-enabled.json', $enabled);
            return true;
        }, ['auth' => 'admin']);
    }
}
