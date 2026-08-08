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
 * The default driver, the fake used throughout the test suite, and the
 * reference against which the persistent drivers are checked. It is not
 * shared between processes, so it needs no locking and no transactions.
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

    public function replace(array $obsolete, ?SegmentInterface $replacement): void
    {
        foreach ($obsolete as $segment) {
            $this->remove($segment->id());
        }

        if ($replacement !== null) {
            $this->prepend($replacement);
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
