<?php
declare(strict_types=1);

namespace Plugins\Gql;

use Core\Context;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Error\Error;
use GraphQL\Utils\AST;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Registry
{
    private static array $fields = ['query' => [], 'mutation' => []];

    public static function registerField(string $kind, string $name, callable $resolver, array $opts): void
    {
        self::$fields[$kind][$name] = [
            'resolver' => $resolver,
            'opts' => $opts
        ];
    }

    public static function getFields(string $kind): array
    {
        return self::$fields[$kind] ?? [];
    }

    public static function getField(string $kind, string $name): ?array
    {
        return self::$fields[$kind][$name] ?? null;
    }
}

class Engine
{
    private static ?Schema $schema = null;

    private static function getJsonScalar(): CustomScalarType
    {
        static $jsonType = null;
        if ($jsonType === null) {
            $jsonType = new CustomScalarType([
                'name' => 'JSON',
                'description' => 'Arbitrary JSON data scalar',
                'serialize' => fn($val) => $val,
                'parseValue' => fn($val) => $val,
                'parseLiteral' => fn($ast, ?array $variables = null) => AST::valueFromASTUntyped($ast, $variables)
            ]);
        }
        return $jsonType;
    }

    private static function getStoreFilterType(): InputObjectType
    {
        static $filterType = null;
        if ($filterType === null) {
            $filterType = new InputObjectType([
                'name' => 'StoreFilter',
                'fields' => [
                    'field' => Type::nonNull(Type::string()),
                    'op' => Type::nonNull(Type::string()),
                    'value' => self::getJsonScalar()
                ]
            ]);
        }
        return $filterType;
    }

    private static function getStoreOrderType(): InputObjectType
    {
        static $orderType = null;
        if ($orderType === null) {
            $orderType = new InputObjectType([
                'name' => 'StoreOrder',
                'fields' => [
                    'field' => Type::nonNull(Type::string()),
                    'dir' => Type::string()
                ]
            ]);
        }
        return $orderType;
    }

    private static function getStoreDocType(): ObjectType
    {
        static $docType = null;
        if ($docType === null) {
            $docType = new ObjectType([
                'name' => 'StoreDoc',
                'fields' => [
                    'id' => Type::string(),
                    'data' => self::getJsonScalar(),
                    'created' => Type::string(),
                    'updated' => Type::string()
                ]
            ]);
        }
        return $docType;
    }

    private static function getVaultFileType(): ObjectType
    {
        static $vaultFileType = null;
        if ($vaultFileType === null) {
            $vaultFileType = new ObjectType([
                'name' => 'VaultFile',
                'fields' => [
                    'id' => Type::string(),
                    'name' => Type::string(),
                    'mime' => Type::string(),
                    'size' => Type::int(),
                    'created' => Type::string(),
                    'data' => Type::string()
                ]
            ]);
        }
        return $vaultFileType;
    }

    private static function getVaultStatusType(): ObjectType
    {
        static $vaultStatusType = null;
        if ($vaultStatusType === null) {
            $vaultStatusType = new ObjectType([
                'name' => 'VaultStatus',
                'fields' => [
                    'unlocked' => Type::boolean()
                ]
            ]);
        }
        return $vaultStatusType;
    }

    private static function getMeType(): ObjectType
    {
        static $meType = null;
        if ($meType === null) {
            $meType = new ObjectType([
                'name' => 'Me',
                'fields' => [
                    'id' => Type::string(),
                    'role' => Type::string(),
                    'isAuthenticated' => Type::boolean()
                ]
            ]);
        }
        return $meType;
    }

    private static function getLoginResultType(): ObjectType
    {
        static $loginType = null;
        if ($loginType === null) {
            $loginType = new ObjectType([
                'name' => 'LoginResult',
                'fields' => [
                    'success' => Type::boolean(),
                    'message' => Type::string(),
                    'role' => Type::string(),
                    'csrfToken' => Type::string()
                ]
            ]);
        }
        return $loginType;
    }

    private static function getE2eeStatusType(): ObjectType
    {
        static $e2eeStatusType = null;
        if ($e2eeStatusType === null) {
            $e2eeStatusType = new ObjectType([
                'name' => 'E2eeStatus',
                'fields' => [
                    'configured' => Type::boolean(),
                    'envelope' => self::getJsonScalar()
                ]
            ]);
        }
        return $e2eeStatusType;
    }

    private static function getE2eeFileType(): ObjectType
    {
        static $e2eeFileType = null;
        if ($e2eeFileType === null) {
            $e2eeFileType = new ObjectType([
                'name' => 'E2eeFileItem',
                'fields' => [
                    'id' => Type::string(),
                    'created' => Type::string(),
                    'v' => Type::int()
                ]
            ]);
        }
        return $e2eeFileType;
    }

    private static function inferTypeForField(string $fieldName): Type
    {
        $json = self::getJsonScalar();
        $storeFilter = self::getStoreFilterType();
        $storeOrder = self::getStoreOrderType();

        return match ($fieldName) {
            // Admin & Vault
            'vault_status' => self::getVaultStatusType(),
            'vault_unlock', 'vault_lock', 'vault_delete', 'e2ee_init', 'e2ee_delete', 'placeholder_set_text' => Type::boolean(),
            'vault_put', 'e2ee_put' => Type::string(),
            'vault_list' => Type::listOf(self::getVaultFileType()),
            'vault_get' => self::getVaultFileType(),
            'e2ee_status' => self::getE2eeStatusType(),
            'e2ee_list' => Type::listOf(self::getE2eeFileType()),
            'e2ee_get' => $json,

            // Auth
            'me' => self::getMeType(),
            'login' => self::getLoginResultType(),
            'logout' => Type::boolean(),

            // Placeholder
            'placeholder_text' => Type::string(),

            // Store
            'store_find' => Type::listOf(self::getStoreDocType()),
            'store_get' => self::getStoreDocType(),
            'store_count' => Type::int(),
            'store_insert' => Type::string(),
            'store_update', 'store_delete' => Type::boolean(),

            default => $json
        };
    }

    private static function inferArgsForField(string $fieldName): array
    {
        $json = self::getJsonScalar();
        $storeFilter = self::getStoreFilterType();
        $storeOrder = self::getStoreOrderType();

        return match ($fieldName) {
            'vault_unlock' => [
                'masterPassword' => ['type' => Type::string()]
            ],
            'vault_put' => [
                'name' => ['type' => Type::string()],
                'mime' => ['type' => Type::string()],
                'data' => ['type' => Type::string()]
            ],
            'vault_get', 'vault_delete', 'e2ee_get', 'e2ee_delete' => [
                'id' => ['type' => Type::nonNull(Type::id())]
            ],
            'e2ee_init' => [
                'envelope' => ['type' => $json]
            ],
            'e2ee_put' => [
                'file' => ['type' => $json]
            ],
            'login' => [
                'username' => ['type' => Type::string()],
                'password' => ['type' => Type::string()]
            ],
            'placeholder_set_text' => [
                'text' => ['type' => Type::string()]
            ],
            'store_find' => [
                'ns' => ['type' => Type::nonNull(Type::string())],
                'where' => ['type' => Type::listOf($storeFilter)],
                'order' => ['type' => $storeOrder],
                'limit' => ['type' => Type::int()],
                'offset' => ['type' => Type::int()]
            ],
            'store_get' => [
                'ns' => ['type' => Type::nonNull(Type::string())],
                'id' => ['type' => Type::nonNull(Type::id())]
            ],
            'store_count' => [
                'ns' => ['type' => Type::nonNull(Type::string())],
                'where' => ['type' => Type::listOf($storeFilter)]
            ],
            'store_insert' => [
                'ns' => ['type' => Type::nonNull(Type::string())],
                'data' => ['type' => Type::nonNull($json)]
            ],
            'store_update' => [
                'ns' => ['type' => Type::nonNull(Type::string())],
                'id' => ['type' => Type::nonNull(Type::id())],
                'data' => ['type' => Type::nonNull($json)],
                'expectedUpdated' => ['type' => Type::string()]
            ],
            'store_delete' => [
                'ns' => ['type' => Type::nonNull(Type::string())],
                'id' => ['type' => Type::nonNull(Type::id())]
            ],
            default => []
        };
    }

    public static function buildSchema(): Schema
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $queryFields = [];
        foreach (Registry::getFields('query') as $fieldName => $reg) {
            $opts = $reg['opts'];
            $resolver = $reg['resolver'];
            $queryFields[$fieldName] = [
                'type' => self::inferTypeForField($fieldName),
                'args' => self::inferArgsForField($fieldName),
                'resolve' => function ($rootValue, array $args, Context $context) use ($resolver, $opts) {
                    $auth = $opts['auth'] ?? 'public';
                    if ($auth === 'user' && !$context->viewer->isAuthenticated()) {
                        throw new Error('UNAUTHORIZED', extensions: ['code' => 'UNAUTHORIZED']);
                    }
                    if ($auth === 'admin' && !$context->viewer->isAdmin()) {
                        throw new Error('FORBIDDEN', extensions: ['code' => 'FORBIDDEN']);
                    }
                    return $resolver($args, $context);
                }
            ];
        }

        $mutationFields = [];
        foreach (Registry::getFields('mutation') as $fieldName => $reg) {
            $opts = $reg['opts'];
            $resolver = $reg['resolver'];
            $mutationFields[$fieldName] = [
                'type' => self::inferTypeForField($fieldName),
                'args' => self::inferArgsForField($fieldName),
                'resolve' => function ($rootValue, array $args, Context $context) use ($resolver, $opts) {
                    $auth = $opts['auth'] ?? 'public';
                    if ($auth === 'user' && !$context->viewer->isAuthenticated()) {
                        throw new Error('UNAUTHORIZED', extensions: ['code' => 'UNAUTHORIZED']);
                    }
                    if ($auth === 'admin' && !$context->viewer->isAdmin()) {
                        throw new Error('FORBIDDEN', extensions: ['code' => 'FORBIDDEN']);
                    }
                    return $resolver($args, $context);
                }
            ];
        }

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => !empty($queryFields) ? $queryFields : [
                '_ping' => ['type' => Type::string(), 'resolve' => fn() => 'pong']
            ]
        ]);

        $schemaConfig = ['query' => $queryType];
        if (!empty($mutationFields)) {
            $schemaConfig['mutation'] = new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutationFields
            ]);
        }

        self::$schema = new Schema($schemaConfig);
        return self::$schema;
    }

    public static function execute(string $query, array $variables, Context $context): array
    {
        if (strlen($query) > 65536) {
            return ['errors' => [['message' => 'Query size limit exceeded (64 KiB)']]];
        }

        try {
            $schema = self::buildSchema();
            $result = GraphQL::executeQuery(
                $schema,
                $query,
                null,
                $context,
                $variables
            );

            return $result->toArray(true);
        } catch (\Throwable $e) {
            $incidentId = \Core\Report::logError($e->getMessage());
            return ['errors' => [['message' => 'Internal server error', 'extensions' => ['incidentId' => $incidentId]]]];
        }
    }
}

class Executor
{
    public static function execute(string $query, array $variables, Context $context): array
    {
        return Engine::execute($query, $variables, $context);
    }
}
