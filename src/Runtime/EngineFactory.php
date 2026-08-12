<?php

declare(strict_types=1);

namespace Lsm\Runtime;

use Lsm\Compaction\NullCompactionPolicy;
use Lsm\Compaction\SizeTieredCompactionPolicy;
use Lsm\Config\EngineConfiguration;
use Lsm\Contract\CompactionPolicyInterface;
use Lsm\Contract\LockInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Contract\TraceListenerInterface;
use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Lock\NullLock;
use Lsm\LsmTree;
use Lsm\MemTable\SortedArrayMemTable;
use Lsm\Segment\SegmentMerger;
use Lsm\Sequence\InMemorySequenceGenerator;
use Lsm\Trace\NullTraceListener;
use Lsm\Wal\NullWriteAheadLog;

/**
 * Assembles an engine from a configuration plus the infrastructure the host
 * provides.
 *
 * The host decides where runs live, how durability works and what a lock
 * means; this class decides how the algorithm is wired. Keeping the two apart
 * is why the core has no dependency on any framework.
 */
final readonly class EngineFactory
{
    public function create(
        EngineConfiguration $config,
        SegmentStoreInterface $segments,
        ?WriteAheadLogInterface $wal = null,
        ?TraceListenerInterface $trace = null,
        ?LockInterface $lock = null,
        ?CompactionPolicyInterface $policy = null,
    ): LsmTree {
        return new LsmTree(
            new SortedArrayMemTable($config->memTableMaxEntries),
            $segments,
            new SegmentMerger(),
            $policy ?? new SizeTieredCompactionPolicy($config->maxRunsPerLevel, $config->bottomLevel),
            $wal ?? new NullWriteAheadLog(),
            new InMemorySequenceGenerator($segments->highestSequence()),
            $trace ?? new NullTraceListener(),
            $lock ?? new NullLock(),
        );
    }

    /**
     * A policy that never compacts, for callers that want to control
     * maintenance entirely from a schedule.
     */
    public function withoutCompaction(): CompactionPolicyInterface
    {
        return new NullCompactionPolicy();
    }
}
