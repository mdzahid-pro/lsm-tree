<?php

declare(strict_types=1);

namespace Lsm;

use Lsm\Contract\CompactionPolicyInterface;
use Lsm\Contract\KeyValueStoreInterface;
use Lsm\Contract\LockInterface;
use Lsm\Contract\MaintenanceInterface;
use Lsm\Contract\MemTableInterface;
use Lsm\Contract\SegmentInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Contract\SequenceGeneratorInterface;
use Lsm\Contract\TraceListenerInterface;
use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Exception\CompactionStalledException;
use Lsm\Exception\KeyNotFoundException;
use Lsm\Model\Entry;
use Lsm\Model\Statistics;
use Lsm\Model\TraceEvent;
use Lsm\Segment\SegmentMerger;

/**
 * A log-structured merge tree.
 *
 * Writes never seek: they land in an ordered in-memory buffer and, once that
 * buffer is full, are sealed into an immutable sorted run. Runs accumulate and
 * are periodically merged into deeper, larger runs. Reads walk the levels from
 * newest to oldest and stop at the first copy of the key they find, which is
 * by construction the current one.
 *
 * Every collaborator is an interface and none is constructed here, so the same
 * algorithm runs entirely in RAM inside a unit test and against a database
 * inside a queue worker.
 */
final class LsmTree implements KeyValueStoreInterface, MaintenanceInterface
{
    /**
     * A ceiling on compaction passes in one call, not a tuning knob.
     *
     * Set far above any real cascade — a tree deep enough to need a thousand
     * merges in one pass has other problems — so it only ever catches a policy
     * that will not settle.
     */
    private const int MAX_COMPACTION_PASSES = 1000;

    /**
     * Guards against the lock being taken twice in one call stack: flush()
     * ends by compacting, and compact() is itself a public entry point.
     */
    private bool $holdsLock = false;

    public function __construct(
        private readonly MemTableInterface $memTable,
        private readonly SegmentStoreInterface $segments,
        private readonly SegmentMerger $merger,
        private readonly CompactionPolicyInterface $compactionPolicy,
        private readonly WriteAheadLogInterface $wal,
        private readonly SequenceGeneratorInterface $sequence,
        private readonly TraceListenerInterface $trace,
        private readonly LockInterface $lock,
    ) {}

    public function put(string $key, string $value): void
    {
        $this->write(new Entry($key, $value, $this->sequence->next()));
    }

    public function delete(string $key): void
    {
        $this->write(Entry::tombstone($key, $this->sequence->next()));
    }

    public function get(string $key): ?string
    {
        $entry = $this->lookup($key);

        if ($entry === null || $entry->isTombstone()) {
            return null;
        }

        return $entry->value;
    }

    public function getOrFail(string $key): string
    {
        return $this->get($key) ?? throw KeyNotFoundException::forKey($key);
    }

    public function has(string $key): bool
    {
        $entry = $this->lookup($key);

        return $entry !== null && !$entry->isTombstone();
    }

    /**
     * Seals the buffer into a level-0 run, then lets the policy decide whether
     * the new shape of the tree needs tidying up.
     *
     * The buffer is only cleared once the run is durably stored, so a failure
     * anywhere in between leaves the data in the buffer and the log where the
     * next attempt will find it.
     */
    public function flush(): void
    {
        if ($this->memTable->count() === 0) {
            return;
        }

        $this->exclusively(function (): void {
            $entries = $this->memTable->entries();

            if ($entries === []) {
                return;
            }

            $segment = $this->segments->transactional(
                fn () => $this->segments->write($entries, 0, count($entries)),
            );

            $this->memTable->clear();
            $this->wal->truncate();

            if ($segment !== null) {
                $this->trace->record(TraceEvent::flush($segment));
            }

            $this->runCompaction();
        });
    }

    /**
     * Applies the policy until it is satisfied.
     *
     * A correct policy stops asking for work once each pass has reduced the
     * runs at the level it touched. Nothing in the interface can enforce that,
     * so the loop is bounded and gives up rather than spinning forever.
     *
     * @throws CompactionStalledException when the policy never settles
     */
    public function compact(): void
    {
        $this->exclusively(fn () => $this->runCompaction());
    }

    /**
     * Restores the buffer from the write-ahead log after an unclean shutdown.
     */
    public function recover(): int
    {
        $records = $this->wal->replay();

        foreach ($records as $entry) {
            $this->memTable->put($entry);
            $this->sequence->advanceTo($entry->sequence);
        }

        return count($records);
    }

    public function statistics(): Statistics
    {
        $runsPerLevel = [];

        foreach ($this->segments->levels() as $level => $runs) {
            $runsPerLevel[$level] = count($runs);
        }

        return new Statistics(
            $this->sequence->current(),
            $this->memTable->count(),
            $this->memTable->capacity(),
            $this->segments->count(),
            $runsPerLevel,
            $this->wal->count(),
        );
    }

    /**
     * @return array<int, list<SegmentInterface>>
     */
    public function levels(): array
    {
        return $this->segments->levels();
    }

    /**
     * The underlying store, for tooling that needs driver-specific operations
     * such as pruning orphaned files.
     *
     * Application code should not need this; if you find yourself reaching for
     * it in a controller, the engine is missing a method.
     */
    public function segmentStore(): SegmentStoreInterface
    {
        return $this->segments;
    }

    /**
     * The write path. Log first, buffer second, and seal the buffer the moment
     * it is full — no in-place update ever happens.
     */
    private function write(Entry $entry): void
    {
        $this->wal->append($entry);
        $this->memTable->put($entry);
        $this->trace->record(TraceEvent::write($entry));

        if ($this->memTable->isFull()) {
            $this->flush();
        }
    }

    /**
     * The read path. Newest source first: the buffer, then each level from
     * shallowest to deepest, and inside a level the newest run first. The
     * first copy found wins, so no version comparison is needed here.
     *
     * Deliberately unlocked. A concurrent compaction may retire a run while
     * this loop is walking it, which costs one stale-but-valid read at worst,
     * because a retired run is by definition superseded by a newer one.
     */
    private function lookup(string $key): ?Entry
    {
        $buffered = $this->memTable->get($key);

        if ($buffered !== null) {
            $this->trace->record(TraceEvent::readHit($key, 'the mem-table'));

            return $buffered;
        }

        foreach ($this->segments->levels() as $runs) {
            foreach ($runs as $segment) {
                if (!$segment->mightContain($key)) {
                    $this->trace->record(TraceEvent::filterSkip($key, $segment));

                    continue;
                }

                $found = $segment->get($key);

                if ($found !== null) {
                    $this->trace->record(TraceEvent::readHit($key, $segment->id()));

                    return $found;
                }

                $this->trace->record(TraceEvent::filterFalsePositive($key, $segment));
            }
        }

        $this->trace->record(TraceEvent::readMiss($key));

        return null;
    }

    private function runCompaction(): void
    {
        $passes = 0;

        while (($plan = $this->compactionPolicy->plan($this->segments)) !== null) {
            if (++$passes > self::MAX_COMPACTION_PASSES) {
                throw CompactionStalledException::afterPasses(
                    self::MAX_COMPACTION_PASSES,
                    $this->compactionPolicy::class,
                );
            }

            $merged = $this->segments->transactional(function () use ($plan) {
                $result = $this->segments->write(
                    $this->merger->merge($plan->inputs, $plan->dropTombstones),
                    $plan->targetLevel,
                    $plan->entryCount(),
                );

                $this->segments->replace($plan->inputs, $result);

                return $result;
            });

            $this->trace->record(TraceEvent::compaction($plan, $merged));
        }
    }

    /**
     * @template TReturn
     *
     * @param callable(): TReturn $work
     * @return TReturn
     */
    private function exclusively(callable $work): mixed
    {
        if ($this->holdsLock) {
            return $work();
        }

        $this->holdsLock = true;

        try {
            return $this->lock->run($work);
        } finally {
            $this->holdsLock = false;
        }
    }
}
