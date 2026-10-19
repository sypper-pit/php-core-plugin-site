<?php
declare(strict_types=1);

namespace SleekDB;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class QueryBuilder
{
    private Store $store;
    private array $conditions = [];
    private ?array $orderBy = null;
    private int $limit = 50;
    private int $offset = 0;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function where(array $condition): self
    {
        $this->conditions[] = $condition;
        return $this;
    }

    public function orderBy(array $orderBy): self
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function get(): array
    {
        $dataDir = $this->store->getStorePath() . 'data/';
        if (!is_dir($dataDir)) {
            return [];
        }

        $files = glob($dataDir . '*.json');
        if (!$files) {
            return [];
        }

        $results = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!$content) continue;
            $doc = json_decode($content, true);
            if (!is_array($doc)) continue;

            if ($this->matchDoc($doc)) {
                $results[] = $doc;
            }
        }

        if ($this->orderBy !== null) {
            $field = key($this->orderBy);
            $dir = strtolower(reset($this->orderBy)) === 'desc' ? -1 : 1;
            usort($results, function ($a, $b) use ($field, $dir) {
                $va = $a[$field] ?? null;
                $vb = $b[$field] ?? null;
                if ($va == $vb) return 0;
                return ($va < $vb ? -1 : 1) * $dir;
            });
        }

        return array_slice($results, $this->offset, $this->limit);
    }

    public function count(): int
    {
        return count($this->get());
    }

    private function matchDoc(array $doc): bool
    {
        foreach ($this->conditions as $cond) {
            if (count($cond) < 3) continue;
            [$field, $op, $val] = $cond;
            $docVal = $doc[$field] ?? null;

            switch (strtoupper($op)) {
                case '=':
                case 'EQ':
                    if ($docVal !== $val) return false;
                    break;
                case '!=':
                case 'NEQ':
                    if ($docVal === $val) return false;
                    break;
                case '>':
                case 'GT':
                    if (!($docVal > $val)) return false;
                    break;
                case '>=':
                case 'GTE':
                    if (!($docVal >= $val)) return false;
                    break;
                case '<':
                case 'LT':
                    if (!($docVal < $val)) return false;
                    break;
                case '<=':
                case 'LTE':
                    if (!($docVal <= $val)) return false;
                    break;
                case 'IN':
                    if (!is_array($val) || !in_array($docVal, $val, true)) return false;
                    break;
                case 'CONTAINS':
                    if (is_string($docVal) && is_string($val)) {
                        if (!str_contains($docVal, $val)) return false;
                    } elseif (is_array($docVal)) {
                        if (!in_array($val, $docVal, true)) return false;
                    } else {
                        return false;
                    }
                    break;
            }
        }
        return true;
    }
}
