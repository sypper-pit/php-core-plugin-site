<?php
declare(strict_types=1);

namespace Plugins\Store;

use Core\Context;
use SleekDB\Store;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Gateway
{
    private static function getStore(string $ns): Store
    {
        [$owner, $name] = explode('.', $ns);
        $dbDir = APP_ROOT . "/data/db/{$owner}";
        return new Store($name, $dbDir);
    }

    public static function find(array $args, Context $context): array
    {
        $ns = $args['ns'];
        if (!Acl::checkAccess($ns, $context, 'read')) {
            throw new \RuntimeException("Access denied to namespace '{$ns}'");
        }

        $store = self::getStore($ns);
        $qb = $store->createQueryBuilder();

        if (!empty($args['where'])) {
            foreach ($args['where'] as $filter) {
                $field = $filter['field'];
                if (!preg_match('/^[a-zA-Z0-9_.]{1,64}$/', $field)) {
                    throw new \InvalidArgumentException("Invalid field name in filter: {$field}");
                }
                $qb->where([$field, $filter['op'], $filter['value'] ?? null]);
            }
        }

        if (!empty($args['order'])) {
            $field = $args['order']['field'];
            $dir = $args['order']['dir'] ?? 'ASC';
            $qb->orderBy([$field => $dir]);
        }

        $qb->limit($args['limit'] ?? 50);
        $qb->offset($args['offset'] ?? 0);

        $results = $qb->get();
        $formatted = [];
        foreach ($results as $doc) {
            $id = $doc['_id'];
            $created = $doc['_created'] ?? date('c');
            $updated = $doc['_updated'] ?? $created;
            unset($doc['_id'], $doc['_created'], $doc['_updated']);
            $formatted[] = [
                'id' => $id,
                'data' => $doc,
                'created' => $created,
                'updated' => $updated
            ];
        }

        return $formatted;
    }

    public static function get(array $args, Context $context): ?array
    {
        $ns = $args['ns'];
        if (!Acl::checkAccess($ns, $context, 'read')) {
            throw new \RuntimeException("Access denied to namespace '{$ns}'");
        }

        $store = self::getStore($ns);
        $doc = $store->findById($args['id']);
        if (!$doc) {
            return null;
        }

        $id = $doc['_id'];
        $created = $doc['_created'] ?? date('c');
        $updated = $doc['_updated'] ?? $created;
        unset($doc['_id'], $doc['_created'], $doc['_updated']);

        return [
            'id' => $id,
            'data' => $doc,
            'created' => $created,
            'updated' => $updated
        ];
    }

    public static function count(array $args, Context $context): int
    {
        $ns = $args['ns'];
        if (!Acl::checkAccess($ns, $context, 'read')) {
            throw new \RuntimeException("Access denied to namespace '{$ns}'");
        }

        $store = self::getStore($ns);
        $qb = $store->createQueryBuilder();

        if (!empty($args['where'])) {
            foreach ($args['where'] as $filter) {
                $qb->where([$filter['field'], $filter['op'], $filter['value'] ?? null]);
            }
        }

        return $qb->count();
    }

    public static function insert(array $args, Context $context): string
    {
        $ns = $args['ns'];
        if (!Acl::checkAccess($ns, $context, 'write')) {
            throw new \RuntimeException("Access denied to namespace '{$ns}'");
        }

        $data = $args['data'];
        unset($data['id'], $data['_id']); // Reject client provided ids

        $now = date('c');
        $data['_created'] = $now;
        $data['_updated'] = $now;

        $store = self::getStore($ns);
        $inserted = $store->insert($data);
        return $inserted['_id'];
    }

    public static function update(array $args, Context $context): bool
    {
        $ns = $args['ns'];
        if (!Acl::checkAccess($ns, $context, 'write')) {
            throw new \RuntimeException("Access denied to namespace '{$ns}'");
        }

        $store = self::getStore($ns);
        $existing = $store->findById($args['id']);
        if (!$existing) {
            return false;
        }

        if (isset($args['expectedUpdated']) && ($existing['_updated'] ?? '') !== $args['expectedUpdated']) {
            throw new \RuntimeException("Optimistic lock failed: record modified since last read");
        }

        $data = $args['data'];
        unset($data['id'], $data['_id']);

        $data['_created'] = $existing['_created'] ?? date('c');
        $data['_updated'] = date('c');

        return $store->updateById($args['id'], $data);
    }

    public static function delete(array $args, Context $context): bool
    {
        $ns = $args['ns'];
        if (!Acl::checkAccess($ns, $context, 'write')) {
            throw new \RuntimeException("Access denied to namespace '{$ns}'");
        }

        $store = self::getStore($ns);
        return $store->deleteById($args['id']);
    }
}
