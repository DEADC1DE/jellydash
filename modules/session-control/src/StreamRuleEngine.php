<?php

declare(strict_types=1);

namespace Mk\Modules\SessionControl;

/**
 * Condition evaluation for stream rules, a PHP port of Tautulli's custom
 * notification conditions: each rule holds a list of
 * {parameter, operator, value, type} conditions plus a free logic string
 * like "({1} and ({2} or {3}))". Conditions with a blank part are skipped
 * (evaluate to true); values may be comma-separated, which behaves as OR.
 *
 * Logic semantics match Tautulli's parser: "and" binds tighter than "or",
 * brackets override, and a blank logic string means every non-blank
 * condition must hold.
 */
final class StreamRuleEngine
{
    private const OPERATORS = [
        'contains',
        'does not contain',
        'is',
        'is not',
        'begins with',
        'does not begin with',
        'ends with',
        'does not end with',
        'is greater than',
        'is less than',
    ];

    private array $tokens = [];
    private int $pos = 0;
    private array $evaluated = [];
    private int $conditionCount = 0;

    /**
     * @param array<int, array{parameter?: string, operator?: string, value?: string, type?: string}> $conditions
     * @param array<string, mixed> $session
     */
    public function evaluate(array $conditions, string $logic, array $session): bool
    {
        $evaluated = [];
        foreach (array_values($conditions) as $condition) {
            $evaluated[] = $this->evaluateCondition($condition, $session);
        }

        if (trim($logic) === '') {
            // No logic string: every non-blank condition must hold.
            foreach ($evaluated as $result) {
                if (!$result) {
                    return false;
                }
            }

            return true;
        }

        $this->tokens = $this->tokenize($logic);
        $this->pos = 0;
        $this->evaluated = $evaluated;
        $this->conditionCount = count($evaluated);

        try {
            $result = $this->parseExpr();
            if ($this->pos !== count($this->tokens)) {
                return false;
            }

            return $result;
        } catch (\ValueError) {
            return false;
        }
    }

    /** @return array<int, string> the operator catalogue for the rule editor */
    public static function operators(): array
    {
        return self::OPERATORS;
    }

    /**
     * @param array{parameter?: string, operator?: string, value?: string, type?: string} $condition
     * @param array<string, mixed> $session
     */
    private function evaluateCondition(array $condition, array $session): bool
    {
        $parameter = trim((string) ($condition['parameter'] ?? ''));
        $operator = trim((string) ($condition['operator'] ?? ''));
        $rawValue = trim((string) ($condition['value'] ?? ''));
        $type = in_array($condition['type'] ?? 'str', ['str', 'int', 'float'], true)
            ? (string) ($condition['type'] ?? 'str')
            : 'str';

        // Blank conditions are skipped, exactly like Tautulli.
        if ($parameter === '' || $operator === '' || $rawValue === '') {
            return true;
        }

        if (!array_key_exists($parameter, $session) || !in_array($operator, self::OPERATORS, true)) {
            return false;
        }

        // "~" means the empty string, comma separates OR-combined values.
        $values = array_map(
            fn (string $value): string => trim($value) === '~' ? '' : trim($value),
            explode(',', $rawValue),
        );

        $actual = $session[$parameter];

        $values = match ($type) {
            'int' => array_map(static fn ($value) => (int) $value, $values),
            'float' => array_map(static fn ($value) => (float) str_replace(',', '.', (string) $value), $values),
            default => array_map(static fn ($value) => mb_strtolower((string) $value), $values),
        };
        $actual = match ($type) {
            'int' => (int) $actual,
            'float' => (float) $actual,
            default => mb_strtolower((string) $actual),
        };

        return match ($operator) {
            'contains' => $this->any($values, fn ($value): bool => $value === '' ? true : mb_strpos((string) $actual, (string) $value) !== false),
            'does not contain' => $this->all($values, fn ($value): bool => $value === '' ? true : mb_strpos((string) $actual, (string) $value) === false),
            'is' => $this->any($values, fn ($value): bool => $actual === $value),
            'is not' => $this->all($values, fn ($value): bool => $actual !== $value),
            'begins with' => $this->any($values, fn ($value): bool => $value === '' ? true : mb_strpos((string) $actual, (string) $value) === 0),
            'does not begin with' => $this->all($values, fn ($value): bool => $value === '' ? true : mb_strpos((string) $actual, (string) $value) !== 0),
            'ends with' => $this->any($values, fn ($value): bool => $value === '' ? true : (string) $actual !== '' && mb_substr((string) $actual, -mb_strlen((string) $value)) === (string) $value),
            'does not end with' => $this->all($values, fn ($value): bool => $value === '' ? true : (string) $actual === '' || mb_substr((string) $actual, -mb_strlen((string) $value)) !== (string) $value),
            'is greater than' => $this->any($values, fn ($value): bool => $actual > $value),
            'is less than' => $this->any($values, fn ($value): bool => $actual < $value),
        };
    }

    /**
     * Recursive descent: expr := term (or term)* · term := factor (and factor)*
     * · factor := '{n}' | '(' expr ')'. Evaluates while parsing.
     */
    private function parseExpr(): bool
    {
        $result = $this->parseTerm();
        while ($this->peek() === 'or') {
            $this->pos++;
            $value = $this->parseTerm();
            $result = $result || $value;
        }

        return $result;
    }

    private function parseTerm(): bool
    {
        $result = $this->parseFactor();
        while ($this->peek() === 'and') {
            $this->pos++;
            $value = $this->parseFactor();
            $result = $result && $value;
        }

        return $result;
    }

    private function parseFactor(): bool
    {
        $token = $this->peek();

        if ($token === null) {
            throw new \ValueError('Unexpected end of logic');
        }

        if ($token === '(') {
            $this->pos++;
            $value = $this->parseExpr();
            if ($this->peek() !== ')') {
                throw new \ValueError('Missing closing bracket');
            }
            $this->pos++;

            return $value;
        }

        if (preg_match('/^\{(\d+)\}$/', (string) $token, $matches) === 1) {
            $index = (int) $matches[1];
            if ($index < 1 || $index > $this->conditionCount) {
                throw new \ValueError('Unknown condition {' . $index . '}');
            }
            $this->pos++;

            return (bool) $this->evaluated[$index - 1];
        }

        throw new \ValueError('Unexpected token "' . $token . '"');
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    /** @return array<int, string> */
    private function tokenize(string $logic): array
    {
        $parts = preg_split('/(\(|\)|\band\b|\bor\b)/', mb_strtolower(trim($logic)), -1, PREG_SPLIT_DELIM_CAPTURE);

        return array_values(array_filter(array_map('trim', $parts ?: []), fn (string $t): bool => $t !== ''));
    }

    private function any(array $values, callable $test): bool
    {
        foreach ($values as $value) {
            if ($test($value)) {
                return true;
            }
        }

        return false;
    }

    private function all(array $values, callable $test): bool
    {
        foreach ($values as $value) {
            if (!$test($value)) {
                return false;
            }
        }

        return true;
    }
}
