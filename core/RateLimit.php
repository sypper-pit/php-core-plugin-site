<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class RateLimit
{
    public static function check(Context $context, string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $hashedId = hash_hmac('sha256', $identifier, 'rate_limit_salt');
        $key = "rl:{$action}:{$hashedId}";
        $now = time();

        $res = $context->gql('query($ns: String!, $where: [StoreFilter!]) { store_find(ns: $ns, where: $where) { id data } }', [
            'ns' => 'core.ratelimit',
            'where' => [['field' => 'key', 'op' => 'EQ', 'value' => $key]]
        ]);

        $docs = $res['data']['store_find'] ?? [];
        if (empty($docs)) {
            $context->gql('mutation($ns: String!, $data: JSON!) { store_insert(ns: $ns, data: $data) }', [
                'ns' => 'core.ratelimit',
                'data' => [
                    'key' => $key,
                    'attempts' => 1,
                    'first_attempt' => $now,
                    'blocked_until' => 0
                ]
            ]);
            return true;
        }

        $doc = $docs[0];
        $id = $doc['id'];
        $data = $doc['data'];

        if (($data['blocked_until'] ?? 0) > $now) {
            return false;
        }

        if (($now - ($data['first_attempt'] ?? $now)) > $windowSeconds) {
            // Reset window
            $data['attempts'] = 1;
            $data['first_attempt'] = $now;
            $data['blocked_until'] = 0;
        } else {
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;
            if ($data['attempts'] > $maxAttempts) {
                $data['blocked_until'] = $now + $windowSeconds;
                $context->gql('mutation($ns: String!, $id: ID!, $data: JSON!) { store_update(ns: $ns, id: $id, data: $data) }', [
                    'ns' => 'core.ratelimit', 'id' => $id, 'data' => $data
                ]);
                return false;
            }
        }

        $context->gql('mutation($ns: String!, $id: ID!, $data: JSON!) { store_update(ns: $ns, id: $id, data: $data) }', [
            'ns' => 'core.ratelimit', 'id' => $id, 'data' => $data
        ]);

        return true;
    }
}
