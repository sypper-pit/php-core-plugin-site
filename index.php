<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

require_once APP_ROOT . '/libs/autoload.php';

use Core\Kernel;
use Core\Request;
use Core\Response;
use Core\Context;
use Core\Session;
use Core\Files;
use Core\Security;
use Plugins\Gql\Executor;

Kernel::boot();

$request = new Request();
$response = new Response();

// Global Context Bootstrapping
$gqlExecutor = function (string $query, array $vars, Context $ctx) {
    return Executor::execute($query, $vars, $ctx);
};

// Initial system context as 'core'
$coreViewer = new \Core\Viewer(null, 'public', null);
$context = new Context('core', $coreViewer, $request, $gqlExecutor);

// Load Plugins
\Core\PluginManager::loadPlugins($context);

// Authenticate session
$viewer = Session::start($context);
$context = new Context('http', $viewer, $request, $gqlExecutor);
\Core\Report::setContext($context);

// Determine Admin Route Path
$adminPathConfig = Files::readConfig('admin-path.json');
$adminPath = $adminPathConfig['path'] ?? '/panel-admin';

$uri = $request->uri;

// Handling Setup
if (!Files::existsConfig('installed.lock')) {
    if ($uri === '/setup') {
        if ($request->method === 'POST') {
            $data = $request->jsonBody ?? [];
            $setupToken = Files::readTextConfig('setup-token.txt');
            if ($setupToken && ($data['setupToken'] ?? '') !== $setupToken) {
                $response->json(['error' => 'Invalid setup token'], 403)->send();
                exit;
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $confirmPassword = $data['confirmPassword'] ?? '';
            if (strlen($username) < 3 || strlen($password) < 6) {
                $response->json(['error' => 'Invalid credentials requirements'], 400)->send();
                exit;
            }
            if ($confirmPassword !== '' && $password !== $confirmPassword) {
                $response->json(['error' => 'Admin passwords do not match'], 400)->send();
                exit;
            }

            // Create admin user using core caller context
            $passHash = password_hash($password, PASSWORD_ARGON2ID);
            $res = $context->withCaller('core')->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
                'ns' => 'auth.users',
                'data' => [
                    'username' => $username,
                    'password_hash' => $passHash,
                    'role' => 'admin'
                ]
            ]);

            // Generate random admin path
            $randomAdminPath = '/admin-' . bin2hex(random_bytes(8));
            Files::writeConfig('admin-path.json', ['path' => $randomAdminPath]);

            // Lock setup atomically
            Files::writeConfig('installed.lock', ['installed_at' => date('c')]);
            Files::removeConfig('setup-token.txt');

            $response->json([
                'success' => true,
                'userId' => $res['data']['store_insert'],
                'adminPath' => $randomAdminPath
            ])->send();
            exit;
        }

        // Render Setup HTML Page
        $tokenRequired = Files::existsConfig('setup-token.txt');
        $html = "
<!DOCTYPE html>
<html>
<head><title>Setup Application</title><link rel='stylesheet' href='/assets/app/app.css'></head>
<body>
<div class='container'>
    <h2>First Time Application Setup</h2>
    <form id='setupForm'>
        " . ($tokenRequired ? "<label>Setup Token (from CLI): <input type='text' name='setupToken' required></label><br>" : "") . "
        <label>Admin Username: <input type='text' name='username' required></label><br>
        <label>Admin Password: <input type='password' name='password' id='adminPassword' required></label><br>
        <label>Confirm Admin Password: <input type='password' name='confirmPassword' id='confirmAdminPassword' required></label><br>
        <label>Master Password (E2EE): <input type='password' id='masterPassword' name='masterPassword' required></label><br>
        <label>Confirm Master Password: <input type='password' id='confirmMasterPassword' name='confirmMasterPassword' required></label><br>
        <button type='submit'>Complete Setup</button>
    </form>
    <div id='setupMsg'></div>
</div>
<script src='/libs/vendor-js/sodium/sodium.js'></script>
<script src='/assets/e2ee/e2ee-worker.js'></script>
<script src='/assets/e2ee/e2ee-client.js'></script>
<script src='/assets/app/app.js'></script>
</body>
</html>";
        $response->html($html)->send();
        exit;
    }
}

// 404 for setup if installed
if ($uri === '/setup') {
    $response->html("<h1>404 Not Found</h1>", 404)->send();
    exit;
}

// Handling GraphQL endpoint
if ($uri === '/graphql') {
    if ($request->method !== 'POST' && $request->method !== 'GET') {
        $response->setStatusCode(405)->send();
        exit;
    }

    if ($request->method === 'POST') {
        if (!str_contains($request->getHeader('content-type') ?? '', 'application/json')) {
            $response->json(['errors' => [['message' => 'Unsupported Media Type']]], 415)->send();
            exit;
        }

        // CSRF Check for mutations (excluding public auth/setup mutations when not authenticated)
        $body = $request->jsonBody ?? [];
        $query = $body['query'] ?? '';
        $isPublicAuthMutation = str_contains($query, 'login') || str_contains($query, 'e2ee_init');
        if (str_contains(strtolower($query), 'mutation') && !$isPublicAuthMutation && $context->viewer->isAuthenticated()) {
            $token = $request->getHeader('x-csrf-token');
            if (!Security::validateCsrfToken($token, $context->viewer->csrfSecret ?? '')) {
                $response->json(['errors' => [['message' => 'Invalid CSRF token']]], 403)->send();
                exit;
            }
        }

        $res = Executor::execute($query, $body['variables'] ?? [], $context);
        $response->json($res)->send();
        exit;
    }

    if ($request->method === 'GET') {
        $query = $request->params['query'] ?? '';
        if (str_contains(strtolower($query), 'mutation')) {
            $response->json(['errors' => [['message' => 'Mutations not allowed via GET']]], 400)->send();
            exit;
        }
        $vars = isset($request->params['variables']) ? json_decode($request->params['variables'], true) : [];
        $res = Executor::execute($query, is_array($vars) ? $vars : [], $context);
        $response->json($res)->send();
        exit;
    }
}

// Admin Panel Endpoint
if ($uri === $adminPath) {
    if (!$context->viewer->isAdmin()) {
        $response->html("<h1>404 Not Found</h1>", 404)->send();
        exit;
    }

    $nonce = Security::getCspNonce();
    $html = "
<!DOCTYPE html>
<html>
<head><title>Admin Panel</title><link rel='stylesheet' href='/assets/app/app.css'></head>
<body>
<div class='container'>
    <h1>Administration Dashboard</h1>
    <div id='adminApp'></div>
</div>
<script nonce='{$nonce}' src='/libs/vendor-js/sodium/sodium.js'></script>
<script nonce='{$nonce}' src='/assets/e2ee/e2ee-client.js'></script>
<script nonce='{$nonce}' src='/assets/app/app.js'></script>
</body>
</html>";
    $response->html($html)->send();
    exit;
}

// Default Landing Page
if ($uri === '/') {
    $res = $context->gql('query { placeholder_text }');
    $msg = htmlspecialchars($res['data']['placeholder_text'] ?? 'Site under maintenance', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = "
<!DOCTYPE html>
<html>
<head><title>Welcome</title><link rel='stylesheet' href='/assets/app/app.css'></head>
<body>
<div class='container'>
    <h1>{$msg}</h1>
</div>
</body>
</html>";
    $response->html($html)->send();
    exit;
}

$response->html("<h1>404 Not Found</h1>", 404)->send();
