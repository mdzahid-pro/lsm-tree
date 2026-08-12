<?php

declare(strict_types=1);

namespace Lsm\Laravel\Events;

/**
 * Runs were merged into one.
 *
 * A null resultSegmentId means every entry was discarded, which happens when
 * a bottom-level merge finds nothing but tombstones.
 */
final readonly class CompactionCompleted
{
    /**
     * @param list<string> $inputSegmentIds
     */
    public function __construct(
        public string $store,
        public array $inputSegmentIds,
        public ?string $resultSegmentId,
        public int $targetLevel,
        public bool $tombstonesDropped,
    ) {
    }
}
