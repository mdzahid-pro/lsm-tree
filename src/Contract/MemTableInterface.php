<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Countable;
use Lsm\Model\Entry;

/**
 * The write buffer that sits in front of the levels.
 *
 * Any structure with ordered iteration will do: a sorted array, a skip list, a
 * red-black tree. The engine only needs the operations below.
 */
interface MemTableInterface extends Countable
{
    public function put(Entry $entry): void;

    public function get(string $key): ?Entry;

    /**
     * True once the buffer has reached the size at which it should be flushed.
     */
    public function isFull(): bool;

    /**
     * The size at which isFull() starts returning true.
     */
    public function capacity(): int;

    /**
     * @return list<Entry> ordered ascending by key
     */
    public function entries(): array;

    public function clear(): void;
}
