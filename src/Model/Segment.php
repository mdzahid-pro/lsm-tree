<?php

declare(strict_types=1);

namespace Lsm\Model;

use Lsm\Contract\KeyFilterInterface;
use Lsm\Contract\SegmentInterface;
use Lsm\Exception\EmptySegmentException;

/**
 * A run held entirely in memory.
 *
 * Because the entries are sorted and never change, a lookup is a binary search
 * guarded by a key-range check and a probabilistic filter. Suitable for runs
 * small enough to keep resident; persistent stores ship their own lazy
 * implementation of the same contract.
 */
final readonly class Segment implements SegmentInterface
{
    /**
     * @param list<Entry> $entries ascending by key, one entry per key
     */
    public function __construct(
        private string $id,
        private int $level,
        private array $entries,
        private KeyFilterInterface $filter,
    ) {
        if ($entries === []) {
            throw EmptySegmentException::create();
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function minKey(): string
    {
        return $this->entries[0]->key;
    }

    public function maxKey(): string
    {
        return $this->entries[count($this->entries) - 1]->key;
    }

    public function mightContain(string $key): bool
    {
        if (strcmp($key, $this->minKey()) < 0 || strcmp($key, $this->maxKey()) > 0) {
            return false;
        }

        return $this->filter->mightContain($key);
    }

    public function get(string $key): ?Entry
    {
        $low = 0;
        $high = count($this->entries) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $comparison = strcmp($this->entries[$middle]->key, $key);

            if ($comparison === 0) {
                return $this->entries[$middle];
            }

            if ($comparison < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return null;
    }

    /**
     * @return list<Entry>
     */
    public function entries(): iterable
    {
        return $this->entries;
    }

    public function filter(): KeyFilterInterface
    {
        return $this->filter;
    }
}
