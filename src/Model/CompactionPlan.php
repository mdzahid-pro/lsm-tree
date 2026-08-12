<?php

declare(strict_types=1);

namespace Lsm\Model;

use Lsm\Contract\SegmentInterface;

/**
 * The output of a compaction policy: which runs to merge, where the result
 * belongs, and whether tombstones may finally be discarded.
 *
 * A plan is a decision, not an action. Keeping the two apart means a policy
 * can be tested against a handful of fake runs with no storage in sight.
 */
final readonly class CompactionPlan
{
    /**
     * @param list<SegmentInterface> $inputs newest run first
     */
    public function __construct(
        public array $inputs,
        public int $targetLevel,
        public bool $dropTombstones,
    ) {
    }

    /**
     * @return list<string>
     */
    public function inputIds(): array
    {
        return array_map(static fn (SegmentInterface $segment): string => $segment->id(), $this->inputs);
    }

    public function entryCount(): int
    {
        return array_sum(array_map(static fn (SegmentInterface $segment): int => $segment->count(), $this->inputs));
    }
}
