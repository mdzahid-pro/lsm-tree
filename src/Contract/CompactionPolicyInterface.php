<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Model\CompactionPlan;

/**
 * Decides *whether* and *what* to compact, never *how*.
 *
 * Size-tiered, levelled and "never compact" strategies are interchangeable
 * because each one only answers this single question.
 */
interface CompactionPolicyInterface
{
    /**
     * @return CompactionPlan|null null when the shape of the tree is acceptable
     */
    public function plan(SegmentStoreInterface $segments): ?CompactionPlan;
}
