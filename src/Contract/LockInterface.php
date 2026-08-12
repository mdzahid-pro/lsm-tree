<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Exception\LockTimeoutException;

/**
 * Mutual exclusion around the operations that rewrite the level hierarchy.
 *
 * Reads and buffered writes are cheap and local; flushing and compaction are
 * not, and two processes doing either at once against a shared store will
 * duplicate or lose runs. The engine asks for exclusivity and does not care
 * whether it is backed by Redis, a database row or nothing at all.
 */
interface LockInterface
{
    /**
     * @template TReturn
     *
     * @param callable(): TReturn $work
     *
     * @return TReturn
     *
     * @throws LockTimeoutException when the lock cannot be acquired in time
     */
    public function run(callable $work): mixed;
}
