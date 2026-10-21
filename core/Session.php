<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Session
{
    private const COOKIE_NAME = '__Host-sid';

    public static function start(Context $context): Viewer
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        $rawCookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (empty($rawCookie) || !is_string($rawCookie) || strlen($rawCookie) < 32) {
            return new Viewer(null, 'public', null);
        }

        $sessionHash = hash('sha256', $rawCookie);
        $res = $context->gql('query($ns: String!, $where: [StoreFilter!]) { store_find(ns: $ns, where: $where) { id data } }', [
            'ns' => 'core.sessions',
            'where' => [['field' => 'hash', 'op' => 'EQ', 'value' => $sessionHash]]
        ]);

        $docs = $res['data']['store_find'] ?? [];
        if (empty($docs)) {
            self::destroyCookie();
            return new Viewer(null, 'public', null);
        }

        $sessionDoc = $docs[0];
        $id = $sessionDoc['id'];
        $data = $sessionDoc['data'];
        $now = time();

        $idleTimeout = 1800; // 30 mins
        $absTimeout = 28800; // 8 hours

        if (($now - ($data['created_at'] ?? 0)) > $absTimeout || ($now - ($data['last_active'] ?? 0)) > $idleTimeout) {
            $context->gql('mutation($ns: String!, $id: ID!) { store_delete(ns: $ns, id: $id) }', ['ns' => 'core.sessions', 'id' => $id]);
            self::destroyCookie();
            return new Viewer(null, 'public', null);
        }

        // Update last active
        $data['last_active'] = $now;
        $context->gql('mutation($ns: String!, $id: ID!, $data: JSON!) { store_update(ns: $ns, id: $id, data: $data) }', [
            'ns' => 'core.sessions', 'id' => $id, 'data' => $data
        ]);

        return new Viewer($data['user_id'] ?? null, $data['role'] ?? 'public', $data['csrf_secret'] ?? null);
    }

    public static function create(Context $context, string $userId, string $role): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $rawToken);
        $csrfSecret = bin2hex(random_bytes(16));
        $now = time();

        $sessionData = [
            'hash' => $sessionHash,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => $now,
            'last_active' => $now,
            'csrf_secret' => $csrfSecret,
            'ua_hash' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')
        ];

        $context->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
            'ns' => 'core.sessions',
            'data' => $sessionData
        ]);

        self::setCookie($rawToken);
        return $csrfSecret;
    }

    public static function destroy(Context $context): void
    {
        $rawCookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!empty($rawCookie) && is_string($rawCookie)) {
            $sessionHash = hash('sha256', $rawCookie);
            $res = $context->gql('query($ns: String!, $where: [StoreFilter!]) { store_find(ns: $ns, where: $where) { id } }', [
                'ns' => 'core.sessions',
                'where' => [['field' => 'hash', 'op' => 'EQ', 'value' => $sessionHash]]
            ]);
            $docs = $res['data']['store_find'] ?? [];
            if (!empty($docs)) {
                $context->gql('mutation($ns: String!, $id: ID!) { store_delete(ns: $ns, id: $id) }', [
                    'ns' => 'core.sessions',
                    'id' => $docs[0]['id']
                ]);
            }
        }
        self::destroyCookie();
    }

    private static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    private static function destroyCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
}
