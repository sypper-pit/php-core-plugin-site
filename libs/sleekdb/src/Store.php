<?php
declare(strict_types=1);

namespace SleekDB;

use SleekDB\Classes\IoHelper;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Store
{
    private string $storeName;
    private string $storePath;
    private array $configuration;

    public function __construct(string $storeName, string $dataDir, array $configuration = [])
    {
        $this->storeName = $storeName;
        $this->storePath = rtrim($dataDir, '/') . '/' . $storeName . '/';
        $this->configuration = array_merge([
            'auto_cache' => false,
            'timeout' => 120,
            'primary_key' => '_id'
        ], $configuration);

        IoHelper::checkFileSystem($this->storePath);
    }

    public function insert(array $data): array
    {
        return $this->insertMany([$data])[0];
    }

    public function insertMany(array $dataList): array
    {
        $results = [];
        $dataDir = $this->storePath . 'data/';
        IoHelper::checkFileSystem($dataDir);

        $lockFile = fopen($this->storePath . 'store.lock', 'w+');
        if ($lockFile) {
            flock($lockFile, LOCK_EX);
        }

        try {
            foreach ($dataList as $data) {
                if (!isset($data['_id'])) {
                    $id = bin2hex(random_bytes(16));
                    $data['_id'] = $id;
                } else {
                    $id = (string)$data['_id'];
                }

                $filePath = $dataDir . $id . '.json';
                $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                IoHelper::writeContent($filePath, $content);
                $results[] = $data;
            }
        } finally {
            if ($lockFile) {
                flock($lockFile, LOCK_UN);
                fclose($lockFile);
            }
        }

        return $results;
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function getStorePath(): string
    {
        return $this->storePath;
    }

    public function findById(string $id): ?array
    {
        $filePath = $this->storePath . 'data/' . $id . '.json';
        if (!file_exists($filePath)) {
            return null;
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function updateById(string $id, array $data): bool
    {
        $filePath = $this->storePath . 'data/' . $id . '.json';
        if (!file_exists($filePath)) {
            return false;
        }

        $lockFile = fopen($this->storePath . 'store.lock', 'w+');
        if ($lockFile) {
            flock($lockFile, LOCK_EX);
        }

        try {
            $data['_id'] = $id;
            $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return IoHelper::writeContent($filePath, $content);
        } finally {
            if ($lockFile) {
                flock($lockFile, LOCK_UN);
                fclose($lockFile);
            }
        }
    }

    public function deleteById(string $id): bool
    {
        $filePath = $this->storePath . 'data/' . $id . '.json';
        if (!file_exists($filePath)) {
            return false;
        }

        $lockFile = fopen($this->storePath . 'store.lock', 'w+');
        if ($lockFile) {
            flock($lockFile, LOCK_EX);
        }

        try {
            return unlink($filePath);
        } finally {
            if ($lockFile) {
                flock($lockFile, LOCK_UN);
                fclose($lockFile);
            }
        }
    }
}
