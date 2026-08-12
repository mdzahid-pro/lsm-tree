<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Model\Entry;

/**
 * Durability for writes that are still only in memory.
 *
 * The engine appends before touching the mem-table and truncates after a
 * successful flush, so a crash can be repaired by replaying the log.
 */
interface WriteAheadLogInterface
{
    public function append(Entry $entry): void;

    /**
     * @return list<Entry> in append order
     */
    public function replay(): array;

    public function truncate(): void;

    /**
     * How many entries are currently unflushed. Worth watching: a log that
     * never shrinks means flushes are failing.
     */
    public function count(): int;
}
