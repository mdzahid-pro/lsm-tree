<?php

declare(strict_types=1);

namespace Lsm\Storage;

use Lsm\Contract\SegmentInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Exception\SegmentNotFoundException;
use Lsm\Model\Entry;
use Lsm\Segment\SegmentFactory;

/**
 * Keeps the whole hierarchy in RAM.
 *
 * The zero-configuration driver and the one the test suite runs against;
 * config/lsm.php ships with the database driver selected. It is not shared
 * between processes, so it needs no locking and no transactions.
 *
 * It is not the reference implementation. Every driver is held to the shared
 * expectations in tests/Feature/SegmentStoreConformanceTest.php.
 */
final class InMemorySegmentStore implements SegmentStoreInterface
{
    /** @var array<int, list<SegmentInterface>> */
    private array $levels = [];

    private int $highestSequence = 0;

    public function __construct(private readonly SegmentFactory $factory) {}

    /**
     * @param iterable<Entry> $entries ascending by key, one entry per key
     */
    public function write(iterable $entries, int $level, ?int $estimatedCount = null): ?SegmentInterface
    {
        $collected = [];

        foreach ($entries as $entry) {
            $collected[] = $entry;
            $this->highestSequence = max($this->highestSequence, $entry->sequence);
        }

        $segment = $this->factory->create($collected, $level, $estimatedCount);

        if ($segment === null) {
            return null;
        }

        $this->prepend($segment);

        return $segment;
    }

    /**
     * $replacement is deliberately unused: write() already stored it. Adding it
     * again here is what left the level holding the merged run twice.
     */
    public function replace(array $obsolete, ?SegmentInterface $replacement): void
    {
        foreach ($obsolete as $segment) {
            $this->remove($segment->id());
        }
    }

    public function levels(): array
    {
        $levels = array_filter($this->levels, static fn (array $runs): bool => $runs !== []);
        ksort($levels, SORT_NUMERIC);

        return $levels;
    }

    public function level(int $level): array
    {
        return $this->levels[$level] ?? [];
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (array $runs): int => count($runs), $this->levels));
    }

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function highestSequence(): int
    {
        return $this->highestSequence;
    }

    private function prepend(SegmentInterface $segment): void
    {
        $runs = $this->levels[$segment->level()] ?? [];
        array_unshift($runs, $segment);
        $this->levels[$segment->level()] = $runs;
    }

    private function remove(string $id): void
    {
        foreach ($this->levels as $level => $runs) {
            foreach ($runs as $index => $segment) {
                if ($segment->id() === $id) {
                    unset($runs[$index]);
                    $this->levels[$level] = array_values($runs);

                    return;
                }
            }
        }

        throw SegmentNotFoundException::forId($id);
    }
}
