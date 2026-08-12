<?php

declare(strict_types=1);

namespace Lsm\Sequence;

use Lsm\Contract\SegmentIdGeneratorInterface;

/**
 * Produces S1, S2, S3 ... — short enough to read on a slide. Replace with a
 * ULID generator when more than one writer creates segments.
 */
final class SequentialSegmentIdGenerator implements SegmentIdGeneratorInterface
{
    public function __construct(
        private readonly string $prefix = 'S',
        private int $counter = 0,
    ) {
    }

    public function next(): string
    {
        return $this->prefix . ++$this->counter;
    }
}
