<?php

declare(strict_types=1);

namespace Lsm\Compaction;

use Lsm\Contract\CompactionPolicyInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Model\CompactionPlan;

/**
 * Never compacts. Useful for isolating flush behaviour in a test, and for
 * showing what read amplification looks like when nobody cleans up.
 */
final readonly class NullCompactionPolicy implements CompactionPolicyInterface
{
    public function plan(SegmentStoreInterface $segments): ?CompactionPlan
    {
        return null;
    }
}
