<?php

declare(strict_types=1);

namespace Lsm\Contract;

/**
 * Names new runs. Sequential in the demo, but a UUID or ULID generator drops
 * straight in when segments are written by more than one process.
 */
interface SegmentIdGeneratorInterface
{
    public function next(): string;
}
