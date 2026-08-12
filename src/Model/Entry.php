<?php

declare(strict_types=1);

namespace Lsm\Model;

use Lsm\Exception\InvalidKeyException;

/**
 * A versioned record. A null value is a tombstone: the record still exists as
 * a deletion marker so that older copies of the key in deeper levels stay
 * shadowed until compaction can safely discard them.
 */
final readonly class Entry
{
    public function __construct(
        public string $key,
        public ?string $value,
        public int $sequence,
    ) {
        if ($key === '') {
            throw InvalidKeyException::empty();
        }
    }

    public static function tombstone(string $key, int $sequence): self
    {
        return new self($key, null, $sequence);
    }

    public function isTombstone(): bool
    {
        return $this->value === null;
    }

    /**
     * @return array{key: string, value: string|null, sequence: int}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value, 'sequence' => $this->sequence];
    }

    /**
     * @param array{key: string, value: string|null, sequence: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['value'], $data['sequence']);
    }
}
