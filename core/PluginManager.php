<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class PluginManager
{
    private static array $enabledPlugins = [];

    public static function loadPlugins(Context $context): void
    {
        $enabled = Files::readConfig('plugins-enabled.json') ?? ['gql', 'store', 'auth', 'admin', 'placeholder'];
        self::$enabledPlugins = $enabled;

        foreach ($enabled as $id) {
            $pluginDir = APP_ROOT . "/plugins/{$id}";
            $manifestPath = "{$pluginDir}/plugin.json";
            $entryPath = "{$pluginDir}/plugin.php";

            if (!file_exists($manifestPath) || !file_exists($entryPath)) {
                continue;
            }

            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($manifest) || ($manifest['id'] ?? '') !== $id) {
                continue;
            }

            // Autoload plugin src/ classes if available
            spl_autoload_register(function (string $class) use ($id, $pluginDir): void {
                $prefix = "Plugins\\" . ucfirst($id) . "\\";
                if (str_starts_with($class, $prefix)) {
                    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                    $file = "{$pluginDir}/src/{$relative}.php";
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });

            require_once $entryPath;
            $className = "Plugins\\" . ucfirst($id) . "\\" . ucfirst($id) . "Plugin";
            if (class_exists($className)) {
                $plugin = new $className($context);
                $plugin->register();
            }
        }
    }

    public static function isEnabled(string $id): bool
    {
        return in_array($id, self::$enabledPlugins, true);
    }
}
