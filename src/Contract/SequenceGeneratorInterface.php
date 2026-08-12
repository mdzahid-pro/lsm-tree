<?php

declare(strict_types=1);

namespace Lsm\Contract;

/**
 * Hands out the monotonically increasing numbers that decide which version of
 * a key wins during a merge.
 */
interface SequenceGeneratorInterface
{
    public function next(): int;

    public function current(): int;

    /**
     * Guarantees that every future value is greater than the one given.
     *
     * Called when replaying a write-ahead log, whose entries may carry higher
     * sequence numbers than anything the segment store knows about. Skipping
     * this hands the same number to two different writes, and the merge rule
     * then picks a winner arbitrarily.
     */
    public function advanceTo(int $value): void;
}
