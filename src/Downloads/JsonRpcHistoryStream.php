<?php

declare(strict_types=1);

namespace Mk\Framework\Downloads;

/** Reads the JSON-RPC result array one record at a time, without retaining the response. */
final class JsonRpcHistoryStream
{
    public const MAX_BYTES = 33554432;
    private const MAX_RECORD_BYTES = 262144;
    private string $state = 'start';
    private string $buffer = '';
    private string $key = '';
    private int $bytes = 0;
    private int $depth = 0;
    private bool $quoted = false;
    private bool $escaped = false;
    private bool $started = false;
    /** @var array<string,mixed> */
    private array $envelope = [];
    /** @var array<string,true> */
    private array $keys = [];

    /** @param \Closure(array<string,mixed>):void $record */
    public function __construct(private readonly \Closure $record)
    {
    }

    public function write(string $chunk): void
    {
        $this->bytes += strlen($chunk);
        if ($this->bytes > self::MAX_BYTES) {
            throw new ProviderException('response_too_large');
        }
        for ($i = 0, $length = strlen($chunk); $i < $length; ++$i) {
            if ($this->quoted && !$this->escaped) {
                $run = strcspn($chunk, "\\\"", $i);
                if ($run > 0) {
                    $this->buffer .= substr($chunk, $i, $run);
                    $this->checkValueSize();
                    $i += $run;
                    if ($i === $length) {
                        break;
                    }
                }
            }
            $this->character($chunk[$i]);
        }
    }

    public function bytesReceived(): int
    {
        return $this->bytes;
    }

    public function finish(): string
    {
        if ($this->state !== 'done' || !isset($this->keys['result'])) {
            throw new ProviderException('invalid_response');
        }
        return json_encode($this->envelope, JSON_THROW_ON_ERROR);
    }

    private function character(string $char): void
    {
        if (in_array($this->state, ['key_value', 'value', 'row'], true)) {
            $this->valueCharacter($char);
            return;
        }
        if (str_contains(" \t\r\n", $char)) {
            return;
        }
        switch ($this->state) {
            case 'start':
                $this->expect($char === '{');
                $this->state = 'key';
                return;
            case 'key':
                $this->expect($char === '"');
                $this->beginValue('key_value');
                $this->valueCharacter($char);
                return;
            case 'colon':
                $this->expect($char === ':');
                $this->state = 'value_start';
                return;
            case 'value_start':
                if ($this->key === 'result') {
                    $this->expect($char === '[');
                    $this->envelope['result'] = [];
                    $this->state = 'first_row';
                } else {
                    $this->beginValue('value');
                    $this->valueCharacter($char);
                }
                return;
            case 'first_row':
                if ($char === ']') {
                    $this->state = 'member_separator';
                    return;
                }
                // A history result contains objects, never scalar or nested array records.
                $this->expect($char === '{');
                $this->beginValue('row');
                $this->valueCharacter($char);
                return;
            case 'next_row':
                $this->expect($char === '{');
                $this->beginValue('row');
                $this->valueCharacter($char);
                return;
            case 'row_separator':
                $this->expect($char === ',' || $char === ']');
                $this->state = $char === ',' ? 'next_row' : 'member_separator';
                return;
            case 'member_separator':
                $this->expect($char === ',' || $char === '}');
                $this->state = $char === ',' ? 'key' : 'done';
                return;
            default:
                throw new ProviderException('invalid_response');
        }
    }

    private function beginValue(string $state): void
    {
        $this->state = $state;
        $this->buffer = '';
        $this->depth = 0;
        $this->quoted = $this->escaped = $this->started = false;
    }

    private function valueCharacter(string $char): void
    {
        if ($this->started && !$this->quoted && $this->depth === 0
            && str_contains(" \t\r\n,}", $char)) {
            $this->completeValue();
            $this->character($char);
            return;
        }
        $this->buffer .= $char;
        $this->checkValueSize();
        $this->started = true;
        if ($this->quoted) {
            if ($this->escaped) {
                $this->escaped = false;
            } elseif ($char === '\\') {
                $this->escaped = true;
            } elseif ($char === '"') {
                $this->quoted = false;
                if ($this->depth === 0) {
                    $this->completeValue();
                }
            }
            return;
        }
        if ($char === '"') {
            $this->quoted = true;
        } elseif ($char === '{' || $char === '[') {
            if (++$this->depth > 64) {
                throw new ProviderException('invalid_response');
            }
        } elseif ($char === '}' || $char === ']') {
            if (--$this->depth <= 0) {
                $this->completeValue();
            }
        }
    }

    private function completeValue(): void
    {
        try {
            $value = json_decode($this->buffer, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderException('invalid_response');
        }
        $state = $this->state;
        $this->buffer = '';
        if ($state === 'key_value') {
            $this->expect(is_string($value) && strlen($value) <= 256 && !isset($this->keys[$value]) && count($this->keys) < 32);
            $this->key = (string) $value;
            $this->keys[$this->key] = true;
            $this->state = 'colon';
        } elseif ($state === 'row') {
            $this->expect(is_array($value) && !array_is_list($value));
            ($this->record)($value);
            $this->state = 'row_separator';
        } else {
            $this->envelope[$this->key] = $value;
            $this->state = 'member_separator';
        }
    }

    private function checkValueSize(): void
    {
        if (strlen($this->buffer) > ($this->state === 'row' ? self::MAX_RECORD_BYTES : 16384)) {
            throw new ProviderException('response_too_large');
        }
    }

    private function expect(bool $valid): void
    {
        if (!$valid) {
            throw new ProviderException('invalid_response');
        }
    }
}
