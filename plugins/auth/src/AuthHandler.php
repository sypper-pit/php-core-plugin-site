<?php
declare(strict_types=1);

namespace Plugins\Auth;

use Core\Context;
use Core\RateLimit;
use Core\Session;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class AuthHandler
{
    public static function me(Context $context): array
    {
        return [
            'id' => $context->viewer->userId,
            'role' => $context->viewer->role,
            'isAuthenticated' => $context->viewer->isAuthenticated()
        ];
    }

    public static function login(array $args, Context $context): array
    {
        $username = $args['username'] ?? '';
        $password = $args['password'] ?? '';

        if (!RateLimit::check($context, 'login', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 5, 300)) {
            throw new \RuntimeException("Too many failed login attempts. Please wait.");
        }

        $res = $context->withCaller('auth')->gql('query($ns: String!, $where: [StoreFilter!]) { store_find(ns: $ns, where: $where) { id data } }', [
            'ns' => 'auth.users',
            'where' => [['field' => 'username', 'op' => 'EQ', 'value' => $username]]
        ]);

        $users = $res['data']['store_find'] ?? [];
        $dummyHash = '$argon2id$v=19$m=65536,t=3,p=1$c29tZXNhbHQ$hashhashhashhashhashhashhashhash';

        if (empty($users)) {
            password_verify($password, $dummyHash);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        $user = $users[0]['data'];
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        $csrfToken = Session::create($context, $users[0]['id'], $user['role'] ?? 'user');

        return [
            'success' => true,
            'role' => $user['role'] ?? 'user',
            'csrfToken' => $csrfToken
        ];
    }

    public static function logout(Context $context): bool
    {
        Session::destroy($context);
        return true;
    }
}
