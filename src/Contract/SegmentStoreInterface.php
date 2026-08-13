<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Exception\SegmentNotFoundException;
use Lsm\Model\Entry;

/**
 * Owns the level hierarchy: where runs live, in what order they are consulted,
 * and how a run is materialised.
 *
 * Materialisation belongs here rather than in the engine because only the
 * store knows whether a run is an array, a file or a set of database rows.
 */
interface SegmentStoreInterface
{
    /**
     * Materialises a sorted stream of entries as a new run at the given level.
     *
     * @param iterable<Entry> $entries ascending by key, one per key
     * @param int|null $estimatedCount an upper bound on the number of
     *                                 entries, used to size the filter
     *                                 before the stream is consumed
     * @return SegmentInterface|null null when the stream was empty
     */
    public function write(iterable $entries, int $level, ?int $estimatedCount = null): ?SegmentInterface;

    /**
     * Retires the inputs of a compaction now that its output is stored.
     *
     * Implementations must remove $obsolete and must NOT store $replacement:
     * write() has already stored it. Storing it a second time leaves the level
     * holding two copies of the same run, which inflates read amplification and
     * can leave the level permanently at the threshold the policy compacts on.
     *
     * $replacement is passed so a store can record what superseded the retired
     * runs — lineage, an audit row, a cache key. A store with no such need
     * ignores it.
     *
     * @param list<SegmentInterface> $obsolete
     * @param SegmentInterface|null $replacement the run write() just stored,
     *                                           or null when the merge produced
     *                                           no entries
     *
     * @throws SegmentNotFoundException when an input is not tracked here
     */
    public function replace(array $obsolete, ?SegmentInterface $replacement): void;

    /**
     * @return array<int, list<SegmentInterface>> level => runs, newest run
     *                                            first, shallowest level first
     */
    public function levels(): array;

    /**
     * @return list<SegmentInterface> the runs of one level, newest first
     */
    public function level(int $level): array;

    public function count(): int;

    /**
     * Runs the callback so that a write followed by a replace is all-or-nothing.
     *
     * Stores without transactions may simply invoke the callback, but must say
     * so in their documentation: a crash mid-compaction then leaves duplicated
     * runs behind.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $work
     * @return TReturn
     */
    public function transactional(callable $work): mixed;

    /**
     * The highest sequence number already persisted, or 0 for an empty store.
     *
     * Used on boot to resume numbering. Getting this wrong reintroduces keys
     * that were deleted in an earlier process.
     */
    public function highestSequence(): int;
}
