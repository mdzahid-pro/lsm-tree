<?php

declare(strict_types=1);

namespace Lsm\Laravel\Events;

/**
 * A buffer was sealed into a new run.
 *
 * Useful for metrics: the rate of these events is your write throughput, and
 * a sudden change in entryCount usually means the buffer size was retuned.
 */
final readonly class MemTableFlushed
{
    public function __construct(
        public string $store,
        public string $segmentId,
        public int $level,
        public int $entryCount,
    ) {}
}
