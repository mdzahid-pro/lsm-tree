<?php

declare(strict_types=1);

namespace Lsm\Lock;

use Lsm\Contract\LockInterface;

/**
 * No mutual exclusion at all.
 *
 * Correct for a store that only one process can reach — the in-memory driver,
 * a test, a single queue worker. Deliberately explicit rather than a nullable
 * dependency, so that "no locking" is a decision recorded in configuration
 * instead of an oversight.
 */
final readonly class NullLock implements LockInterface
{
    public function run(callable $work): mixed
    {
        return $work();
    }
}
