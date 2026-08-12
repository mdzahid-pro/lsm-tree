<?php

declare(strict_types=1);

namespace Lsm\Laravel\Storage;

use Illuminate\Database\ConnectionInterface;
use Lsm\Contract\KeyFilterFactoryInterface;
use Lsm\Contract\SegmentIdGeneratorInterface;
use Lsm\Contract\SegmentInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Exception\SegmentNotFoundException;
use Lsm\Filter\BloomFilter;
use Lsm\Filter\NullKeyFilter;
use Lsm\Model\Entry;

/**
 * Keeps the level hierarchy in two tables: one row per run, one row per entry.
 *
 * Runs are written once and never updated, so the only writes this store ever
 * issues are inserts and deletes. Compaction inserts the merged run and
 * deletes its inputs inside a single transaction, which means a crash halfway
 * through leaves the tree exactly as it was.
 *
 * The metadata for every run is cached in memory for the life of the instance
 * because the read path consults it on every lookup; it is invalidated
 * whenever this instance changes the hierarchy.
 */
final class DatabaseSegmentStore implements SegmentStoreInterface
{
    /** @var array<int, list<SegmentInterface>>|null */
    private ?array $cachedLevels = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SegmentIdGeneratorInterface $ids,
        private readonly KeyFilterFactoryInterface $filters,
        private readonly string $store = 'default',
        private readonly string $segmentsTable = 'lsm_segments',
        private readonly string $entriesTable = 'lsm_entries',
        private readonly int $chunkSize = 1000,
    ) {
    }

    /**
     * @param iterable<Entry> $entries ascending by key, one entry per key
     */
    public function write(iterable $entries, int $level, ?int $estimatedCount = null): ?SegmentInterface
    {
        $id = $this->ids->next();
        $filter = $this->filters->builder($estimatedCount ?? 0);

        $buffer = [];
        $count = 0;
        $minKey = null;
        $maxKey = null;
        $maxSequence = 0;

        foreach ($entries as $entry) {
            $minKey ??= $entry->key;
            $maxKey = $entry->key;
            $maxSequence = max($maxSequence, $entry->sequence);
            $count++;

            $filter->add($entry->key);

            $buffer[] = [
                'store' => $this->store,
                'segment_id' => $id,
                'entry_key' => $entry->key,
                'entry_value' => $entry->value,
                'sequence' => $entry->sequence,
            ];

            if (count($buffer) >= $this->chunkSize) {
                $this->connection->table($this->entriesTable)->insert($buffer);
                $buffer = [];
            }
        }

        if ($count === 0) {
            return null;
        }

        if ($buffer !== []) {
            $this->connection->table($this->entriesTable)->insert($buffer);
        }

        $built = $filter->build();

        $this->connection->table($this->segmentsTable)->insert([
            'store' => $this->store,
            'segment_id' => $id,
            'level' => $level,
            'entry_count' => $count,
            'min_key' => $minKey,
            'max_key' => $maxKey,
            'max_sequence' => $maxSequence,
            'filter_bits' => $built instanceof BloomFilter ? base64_encode($built->bits()) : null,
            'filter_size' => $built instanceof BloomFilter ? $built->size() : 0,
            'filter_hashes' => $built instanceof BloomFilter ? $built->hashCount() : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->cachedLevels = null;

        return new DatabaseSegment(
            $this->connection,
            $this->entriesTable,
            $this->store,
            $id,
            $level,
            $count,
            (string) $minKey,
            (string) $maxKey,
            $built,
            $this->chunkSize,
        );
    }

    public function replace(array $obsolete, ?SegmentInterface $replacement): void
    {
        $ids = array_map(static fn (SegmentInterface $segment): string => $segment->id(), $obsolete);

        if ($ids !== []) {
            $deleted = $this->connection->table($this->segmentsTable)
                ->where('store', $this->store)
                ->whereIn('segment_id', $ids)
                ->delete();

            if ($deleted !== count($ids)) {
                throw SegmentNotFoundException::forId(implode(', ', $ids));
            }

            $this->connection->table($this->entriesTable)
                ->where('store', $this->store)
                ->whereIn('segment_id', $ids)
                ->delete();
        }

        $this->cachedLevels = null;
    }

    public function levels(): array
    {
        if ($this->cachedLevels !== null) {
            return $this->cachedLevels;
        }

        /** @var list<object> $rows */
        $rows = $this->connection->table($this->segmentsTable)
            ->where('store', $this->store)
            ->orderBy('level')
            ->orderByDesc('id')
            ->get()
            ->all();

        $levels = [];

        foreach ($rows as $row) {
            $levels[(int) $row->level][] = $this->hydrate($row);
        }

        ksort($levels, SORT_NUMERIC);

        return $this->cachedLevels = $levels;
    }

    public function level(int $level): array
    {
        return $this->levels()[$level] ?? [];
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (array $runs): int => count($runs), $this->levels()));
    }

    public function transactional(callable $work): mixed
    {
        return $this->connection->transaction(function () use ($work) {
            $this->cachedLevels = null;

            return $work();
        });
    }

    public function highestSequence(): int
    {
        /** @var int|string|null $max */
        $max = $this->connection->table($this->segmentsTable)
            ->where('store', $this->store)
            ->max('max_sequence');

        return (int) ($max ?? 0);
    }

    /**
     * Discards the cached hierarchy. Call it when another process may have
     * compacted the same store — a long-running worker that never does this
     * will keep reading runs that no longer exist.
     */
    public function refresh(): void
    {
        $this->cachedLevels = null;
    }

    /**
     * @param object{
     *     segment_id: string,
     *     level: int,
     *     entry_count: int,
     *     min_key: string,
     *     max_key: string,
     *     filter_bits: string|null,
     *     filter_size: int,
     *     filter_hashes: int
     * } $row
     */
    private function hydrate(object $row): DatabaseSegment
    {
        $bits = $row->filter_bits === null ? false : base64_decode($row->filter_bits, true);

        // A filter that cannot be decoded must degrade to "always maybe".
        // Substituting an empty bit vector instead would produce false
        // negatives, and a false negative silently hides live data.
        $filter = $bits === false || (int) $row->filter_size === 0
            ? new NullKeyFilter()
            : new BloomFilter($bits, (int) $row->filter_size, (int) $row->filter_hashes);

        return new DatabaseSegment(
            $this->connection,
            $this->entriesTable,
            $this->store,
            $row->segment_id,
            (int) $row->level,
            (int) $row->entry_count,
            $row->min_key,
            $row->max_key,
            $filter,
            $this->chunkSize,
        );
    }
}
