<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Context
{
    public string $caller; // 'core' or plugin id (e.g., 'auth', 'admin')
    public Viewer $viewer;
    public Request $request;
    private $gqlExecutor;

    public function __construct(string $caller, Viewer $viewer, Request $request, callable $gqlExecutor)
    {
        $this->caller = $caller;
        $this->viewer = $viewer;
        $this->request = $request;
        $this->gqlExecutor = $gqlExecutor;
    }

    public function gql(string $query, array $vars = []): array
    {
        return ($this->gqlExecutor)($query, $vars, $this);
    }

    public function vaultPut(string $name, string $mime, string $data): string
    {
        $res = $this->gql('mutation($name: String!, $mime: String!, $data: String!) { vault_put(name: $name, mime: $mime, data: $data) }', [
            'name' => $name, 'mime' => $mime, 'data' => $data
        ]);
        if (!empty($res['errors'])) {
            throw new \RuntimeException($res['errors'][0]['message']);
        }
        return $res['data']['vault_put'];
    }

    public function vaultGet(string $id): ?array
    {
        $res = $this->gql('query($id: ID!) { vault_get(id: $id) { id name mime size created data } }', ['id' => $id]);
        return $res['data']['vault_get'] ?? null;
    }

    public function vaultList(): array
    {
        $res = $this->gql('query { vault_list { id name mime size created } }');
        return $res['data']['vault_list'] ?? [];
    }

    public function vaultDelete(string $id): bool
    {
        $res = $this->gql('mutation($id: ID!) { vault_delete(id: $id) }', ['id' => $id]);
        return (bool)($res['data']['vault_delete'] ?? false);
    }
}
