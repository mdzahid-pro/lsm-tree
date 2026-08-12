<?php

declare(strict_types=1);

namespace Lsm\Compaction;

use Lsm\Contract\CompactionPolicyInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Exception\InvalidConfigurationException;
use Lsm\Model\CompactionPlan;

/**
 * Size-tiered strategy: once a level holds too many runs, merge all of them
 * into a single run one level deeper.
 *
 * The shallowest overfull level is chosen first, which keeps the hot L0 read
 * path short. Levels at or below the configured floor merge in place, so the
 * tree stops growing downwards.
 */
final readonly class SizeTieredCompactionPolicy implements CompactionPolicyInterface
{
    public function __construct(
        private int $maxRunsPerLevel = 4,
        private int $bottomLevel = 2,
    ) {
        if ($maxRunsPerLevel < 2) {
            throw InvalidConfigurationException::invalidValue('compaction.max_runs_per_level', 'at least 2');
        }

        if ($bottomLevel < 1) {
            throw InvalidConfigurationException::invalidValue('compaction.bottom_level', 'at least 1');
        }
    }

    public function plan(SegmentStoreInterface $segments): ?CompactionPlan
    {
        foreach ($segments->levels() as $level => $runs) {
            if (count($runs) < $this->maxRunsPerLevel) {
                continue;
            }

            return new CompactionPlan(
                $runs,
                min($level + 1, $this->bottomLevel),
                $this->mayDropTombstones($level),
            );
        }

        return null;
    }

    /**
     * A tombstone may only be discarded once no older copy of its key can
     * survive it. That is true in exactly one case: the merge is happening in
     * place at the bottom level, so its inputs are every run that level has
     * and there is no level beneath it.
     *
     * Discarding a tombstone while an unmerged run still holds an older copy
     * of the same key brings the deleted value back to life. This method is
     * the only thing standing between the engine and that bug.
     */
    private function mayDropTombstones(int $level): bool
    {
        return $level >= $this->bottomLevel;
    }
}
