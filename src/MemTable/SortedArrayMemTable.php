<?php

declare(strict_types=1);

namespace Lsm\MemTable;

use Lsm\Contract\MemTableInterface;
use Lsm\Exception\InvalidConfigurationException;
use Lsm\Model\Entry;

/**
 * The simplest structure that satisfies the contract: a hash map kept in
 * insertion order and sorted on the way out.
 *
 * A production engine uses a skip list so that iteration is already ordered;
 * swapping this class for one is a constructor change, nothing more.
 */
final class SortedArrayMemTable implements MemTableInterface
{
    /** @var array<string, Entry> */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries)
    {
        if ($maxEntries < 1) {
            throw InvalidConfigurationException::invalidValue('memtable.max_entries', 'at least 1');
        }
    }

    public function put(Entry $entry): void
    {
        $this->entries[$entry->key] = $entry;
    }

    public function get(string $key): ?Entry
    {
        return $this->entries[$key] ?? null;
    }

    public function isFull(): bool
    {
        return count($this->entries) >= $this->maxEntries;
    }

    public function entries(): array
    {
        $sorted = $this->entries;
        ksort($sorted, SORT_STRING);

        return array_values($sorted);
    }

    public function capacity(): int
    {
        return $this->maxEntries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
