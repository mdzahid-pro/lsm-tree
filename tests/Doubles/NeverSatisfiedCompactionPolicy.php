<?php

declare(strict_types=1);

namespace Lsm\Tests\Doubles;

use Lsm\Contract\CompactionPolicyInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Model\CompactionPlan;

/**
 * A policy that keeps asking for the same work forever.
 *
 * It merges level 0 back into level 0, so every pass leaves the tree in the
 * shape that made it ask in the first place. Stands in for a third-party policy
 * with a termination bug, which the interface cannot prevent.
 */
final readonly class NeverSatisfiedCompactionPolicy implements CompactionPolicyInterface
{
    public function plan(SegmentStoreInterface $segments): ?CompactionPlan
    {
        $runs = $segments->level(0);

        if ($runs === []) {
            return null;
        }

        return new CompactionPlan($runs, 0, false);
    }
}
