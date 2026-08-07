<?php

declare(strict_types=1);

namespace Lsm\Contract;

/**
 * Accumulates a filter one key at a time.
 *
 * A builder rather than a build(array $keys) call because a run may be written
 * from a stream of ten million entries: buffering every key in order to size
 * the filter would defeat the point of streaming.
 */
interface KeyFilterBuilderInterface
{
    public function add(string $key): void;

    public function build(): KeyFilterInterface;
}
