<?php
declare(strict_types=1);

namespace Plugins\Gql;

use Core\Context;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

class Lexer
{
    private string $source;
    private int $len;
    private int $pos = 0;

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->len = strlen($source);
    }

    public function tokenize(): array
    {
        $tokens = [];
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];

            if (ctype_space($ch) || $ch === ',') {
                $this->pos++;
                continue;
            }

            if ($ch === '#') {
                while ($this->pos < $this->len && $this->source[$this->pos] !== "\n") {
                    $this->pos++;
                }
                continue;
            }

            if (str_contains('{}():![]=@$', $ch)) {
                $tokens[] = ['type' => 'PUNCT', 'value' => $ch];
                $this->pos++;
                continue;
            }

            if ($ch === '"') {
                $tokens[] = ['type' => 'STRING', 'value' => $this->readString()];
                continue;
            }

            if (ctype_digit($ch) || $ch === '-') {
                $tokens[] = $this->readNumber();
                continue;
            }

            if (ctype_alpha($ch) || $ch === '_') {
                $name = $this->readName();
                $tokens[] = ['type' => 'NAME', 'value' => $name];
                continue;
            }

            throw new \RuntimeException("Unexpected character '$ch' at position {$this->pos}");
        }

        $tokens[] = ['type' => 'EOF', 'value' => ''];
        return $tokens;
    }

    private function readName(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->len && (ctype_alnum($this->source[$this->pos]) || $this->source[$this->pos] === '_')) {
            $this->pos++;
        }
        return substr($this->source, $start, $this->pos - $start);
    }

    private function readString(): string
    {
        $this->pos++; // skip opening quote
        $str = '';
        while ($this->pos < $this->len) {
            $ch = $this->source[$this->pos];
            if ($ch === '"') {
                $this->pos++;
                return $str;
            }
            if ($ch === '\\') {
                $this->pos++;
                $ch = $this->source[$this->pos] ?? '';
            }
            $str .= $ch;
            $this->pos++;
        }
        throw new \RuntimeException("Unterminated string");
    }

    private function readNumber(): array
    {
        $start = $this->pos;
        if ($this->source[$this->pos] === '-') $this->pos++;
        while ($this->pos < $this->len && ctype_digit($this->source[$this->pos])) {
            $this->pos++;
        }
        $isFloat = false;
        if ($this->pos < $this->len && $this->source[$this->pos] === '.') {
            $isFloat = true;
            $this->pos++;
            while ($this->pos < $this->len && ctype_digit($this->source[$this->pos])) {
                $this->pos++;
            }
        }
        $val = substr($this->source, $start, $this->pos - $start);
        return [
            'type' => $isFloat ? 'FLOAT' : 'INT',
            'value' => $isFloat ? (float)$val : (int)$val
        ];
    }
}

class Parser
{
    private array $tokens;
    private int $pos = 0;

    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function parse(): array
    {
        $ops = [];
        $frags = [];

        while ($this->peek()['type'] !== 'EOF') {
            if ($this->peek()['type'] === 'NAME' && $this->peek()['value'] === 'fragment') {
                $frags[] = $this->parseFragment();
            } else {
                $ops[] = $this->parseOperation();
            }
        }

        return ['type' => 'Document', 'operations' => $ops, 'fragments' => $frags];
    }

    private function peek(): array
    {
        return $this->tokens[$this->pos] ?? ['type' => 'EOF', 'value' => ''];
    }

    private function consume(): array
    {
        $tok = $this->peek();
        $this->pos++;
        return $tok;
    }

    private function expect(string $type, ?string $value = null): array
    {
        $tok = $this->consume();
        if ($tok['type'] !== $type || ($value !== null && $tok['value'] !== $value)) {
            throw new \RuntimeException("Expected {$type} '{$value}', got {$tok['type']} '{$tok['value']}'");
        }
        return $tok;
    }

    private function parseOperation(): array
    {
        $type = 'query';
        if ($this->peek()['type'] === 'NAME' && in_array($this->peek()['value'], ['query', 'mutation'], true)) {
            $type = $this->consume()['value'];
        }

        $name = null;
        if ($this->peek()['type'] === 'NAME') {
            $name = $this->consume()['value'];
        }

        $vars = [];
        if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === '(') {
            $vars = $this->parseVarDefinitions();
        }

        $selectionSet = $this->parseSelectionSet();

        return [
            'type' => 'Operation',
            'kind' => $type,
            'name' => $name,
            'variables' => $vars,
            'selectionSet' => $selectionSet
        ];
    }

    private function parseVarDefinitions(): array
    {
        $this->expect('PUNCT', '(');
        $vars = [];
        while ($this->peek()['value'] !== ')') {
            $this->expect('PUNCT', '$');
            $varName = $this->expect('NAME')['value'];
            $this->expect('PUNCT', ':');
            $varType = $this->expect('NAME')['value'];
            $vars[$varName] = ['type' => $varType];
        }
        $this->expect('PUNCT', ')');
        return $vars;
    }

    private function parseSelectionSet(): array
    {
        $this->expect('PUNCT', '{');
        $selections = [];
        while ($this->peek()['value'] !== '}') {
            if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === '...') {
                // Fragment spread or inline fragment
                $this->consume();
                if ($this->peek()['type'] === 'NAME' && $this->peek()['value'] === 'on') {
                    $this->consume();
                    $typeCondition = $this->expect('NAME')['value'];
                    $selections[] = [
                        'kind' => 'InlineFragment',
                        'typeCondition' => $typeCondition,
                        'selectionSet' => $this->parseSelectionSet()
                    ];
                } else {
                    $fragName = $this->expect('NAME')['value'];
                    $selections[] = ['kind' => 'FragmentSpread', 'name' => $fragName];
                }
            } else {
                $selections[] = $this->parseField();
            }
        }
        $this->expect('PUNCT', '}');
        return $selections;
    }

    private function parseField(): array
    {
        $alias = null;
        $name = $this->expect('NAME')['value'];

        if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === ':') {
            $this->consume();
            $alias = $name;
            $name = $this->expect('NAME')['value'];
        }

        $args = [];
        if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === '(') {
            $args = $this->parseArguments();
        }

        $selectionSet = null;
        if ($this->peek()['type'] === 'PUNCT' && $this->peek()['value'] === '{') {
            $selectionSet = $this->parseSelectionSet();
        }

        return [
            'kind' => 'Field',
            'alias' => $alias,
            'name' => $name,
            'arguments' => $args,
            'selectionSet' => $selectionSet
        ];
    }

    private function parseArguments(): array
    {
        $this->expect('PUNCT', '(');
        $args = [];
        while ($this->peek()['value'] !== ')') {
            $argName = $this->expect('NAME')['value'];
            $this->expect('PUNCT', ':');
            $val = $this->parseValue();
            $args[$argName] = $val;
        }
        $this->expect('PUNCT', ')');
        return $args;
    }

    private function parseValue(): mixed
    {
        $tok = $this->peek();
        if ($tok['type'] === 'PUNCT' && $tok['value'] === '$') {
            $this->consume();
            return ['kind' => 'Variable', 'name' => $this->expect('NAME')['value']];
        }
        if ($tok['type'] === 'STRING' || $tok['type'] === 'INT' || $tok['type'] === 'FLOAT') {
            $this->consume();
            return $tok['value'];
        }
        if ($tok['type'] === 'NAME') {
            $this->consume();
            if ($tok['value'] === 'true') return true;
            if ($tok['value'] === 'false') return false;
            if ($tok['value'] === 'null') return null;
            return $tok['value']; // enum or identifier
        }
        if ($tok['type'] === 'PUNCT' && $tok['value'] === '[') {
            $this->consume();
            $list = [];
            while ($this->peek()['value'] !== ']') {
                $list[] = $this->parseValue();
            }
            $this->expect('PUNCT', ']');
            return $list;
        }
        if ($tok['type'] === 'PUNCT' && $tok['value'] === '{') {
            $this->consume();
            $obj = [];
            while ($this->peek()['value'] !== '}') {
                $k = $this->expect('NAME')['value'];
                $this->expect('PUNCT', ':');
                $obj[$k] = $this->parseValue();
            }
            $this->expect('PUNCT', '}');
            return $obj;
        }

        throw new \RuntimeException("Unexpected token in value: {$tok['type']} '{$tok['value']}'");
    }

    private function parseFragment(): array
    {
        $this->expect('NAME', 'fragment');
        $name = $this->expect('NAME')['value'];
        $this->expect('NAME', 'on');
        $typeCond = $this->expect('NAME')['value'];
        $selectionSet = $this->parseSelectionSet();

        return [
            'type' => 'FragmentDefinition',
            'name' => $name,
            'typeCondition' => $typeCond,
            'selectionSet' => $selectionSet
        ];
    }
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

    public static function getField(string $kind, string $name): ?array
    {
        return self::$fields[$kind][$name] ?? null;
    }
}

class Executor
{
    public static function execute(string $query, array $variables, Context $context): array
    {
        if (strlen($query) > 65536) {
            return ['errors' => [['message' => 'Query size limit exceeded (64 KiB)']]];
        }

        try {
            $lexer = new Lexer($query);
            $tokens = $lexer->tokenize();
            $parser = new Parser($tokens);
            $doc = $parser->parse();

            $op = $doc['operations'][0] ?? null;
            if (!$op) {
                return ['errors' => [['message' => 'No operation specified']]];
            }

            $results = [];
            $kind = $op['kind'];

            foreach ($op['selectionSet'] as $selection) {
                if ($selection['kind'] !== 'Field') continue;

                $fieldName = $selection['name'];
                $alias = $selection['alias'] ?? $fieldName;
                $reg = Registry::getField($kind, $fieldName);

                if (!$reg) {
                    return ['errors' => [['message' => "Unknown field '{$fieldName}' on type '{$kind}'"]]];
                }

                $opts = $reg['opts'];

                // Auth check
                $auth = $opts['auth'] ?? 'public';
                if ($auth === 'user' && !$context->viewer->isAuthenticated()) {
                    return ['errors' => [['message' => 'UNAUTHORIZED', 'extensions' => ['code' => 'UNAUTHORIZED']]]];
                }
                if ($auth === 'admin' && !$context->viewer->isAdmin()) {
                    return ['errors' => [['message' => 'FORBIDDEN', 'extensions' => ['code' => 'FORBIDDEN']]]];
                }

                // Resolve args
                $rawArgs = $selection['arguments'] ?? [];
                $resolvedArgs = self::resolveVars($rawArgs, $variables);

                $results[$alias] = ($reg['resolver'])($resolvedArgs, $context);
            }

            return ['data' => $results];
        } catch (\Throwable $e) {
            $incidentId = \Core\Report::logError($e->getMessage());
            return ['errors' => [['message' => 'Internal server error', 'extensions' => ['incidentId' => $incidentId]]]];
        }
    }

    private static function resolveVars(mixed $arg, array $variables): mixed
    {
        if (is_array($arg)) {
            if (isset($arg['kind']) && $arg['kind'] === 'Variable') {
                return $variables[$arg['name']] ?? null;
            }
            $out = [];
            foreach ($arg as $k => $v) {
                $out[$k] = self::resolveVars($v, $variables);
            }
            return $out;
        }
        return $arg;
    }
}
