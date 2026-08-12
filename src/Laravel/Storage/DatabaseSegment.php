<?php

declare(strict_types=1);

namespace Lsm\Laravel\Storage;

use Generator;
use Illuminate\Database\ConnectionInterface;
use Lsm\Contract\KeyFilterInterface;
use Lsm\Contract\SegmentInterface;
use Lsm\Model\Entry;

/**
 * A run stored as rows, read on demand.
 *
 * Nothing but metadata lives in PHP memory: the key range, the entry count and
 * the packed filter. A lookup is one indexed query, and iterating the run for
 * a compaction streams it in chunks. This is what allows a segment of ten
 * million entries to be a first-class citizen of the same engine that runs
 * entirely in RAM during a unit test.
 */
final readonly class DatabaseSegment implements SegmentInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $entriesTable,
        private string $store,
        private string $id,
        private int $level,
        private int $count,
        private string $minKey,
        private string $maxKey,
        private KeyFilterInterface $filter,
        private int $chunkSize = 1000,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function minKey(): string
    {
        return $this->minKey;
    }

    public function maxKey(): string
    {
        return $this->maxKey;
    }

    public function mightContain(string $key): bool
    {
        if (strcmp($key, $this->minKey) < 0 || strcmp($key, $this->maxKey) > 0) {
            return false;
        }

        return $this->filter->mightContain($key);
    }

    public function get(string $key): ?Entry
    {
        /** @var object{entry_key: string, entry_value: string|null, sequence: int}|null $row */
        $row = $this->connection->table($this->entriesTable)
            ->where('store', $this->store)
            ->where('segment_id', $this->id)
            ->where('entry_key', $key)
            ->first(['entry_key', 'entry_value', 'sequence']);

        if ($row === null) {
            return null;
        }

        return new Entry($row->entry_key, $row->entry_value, (int) $row->sequence);
    }

    /**
     * Rows are inserted in ascending key order and never updated, so the
     * auto-incrementing primary key is already in key order. Ordering by id
     * therefore returns entries sorted by key while letting the database walk
     * the clustered index instead of sorting.
     *
     * @return Generator<Entry>
     */
    public function entries(): iterable
    {
        $lastId = 0;

        while (true) {
            /** @var list<object{id: int, entry_key: string, entry_value: string|null, sequence: int}> $rows */
            $rows = $this->connection->table($this->entriesTable)
                ->where('store', $this->store)
                ->where('segment_id', $this->id)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($this->chunkSize)
                ->get(['id', 'entry_key', 'entry_value', 'sequence'])
                ->all();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                yield new Entry($row->entry_key, $row->entry_value, (int) $row->sequence);
            }
        }
    }
}
