<?php

declare(strict_types=1);

namespace Lsm\Model;

/**
 * A snapshot of engine state, safe to log, serialise or assert against.
 *
 * A value object rather than an array so that adding a metric is a compile
 * time concern for consumers instead of a silent key that nobody reads.
 */
final readonly class Statistics
{
    /**
     * @param array<int, int> $runsPerLevel level => number of runs
     */
    public function __construct(
        public int $sequence,
        public int $buffered,
        public int $bufferCapacity,
        public int $runs,
        public array $runsPerLevel,
        public int $walEntries,
    ) {}

    public function levels(): int
    {
        return count($this->runsPerLevel);
    }

    /**
     * The worst-case number of runs a read must consider. A useful early
     * warning: if this climbs, compaction is not keeping up.
     */
    public function readAmplification(): int
    {
        return $this->runs;
    }

    /**
     * @return array{
     *     sequence: int,
     *     buffered: int,
     *     buffer_capacity: int,
     *     runs: int,
     *     runs_per_level: array<int, int>,
     *     wal_entries: int,
     *     levels: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'buffered' => $this->buffered,
            'buffer_capacity' => $this->bufferCapacity,
            'runs' => $this->runs,
            'runs_per_level' => $this->runsPerLevel,
            'wal_entries' => $this->walEntries,
            'levels' => $this->levels(),
        ];
    }
}
