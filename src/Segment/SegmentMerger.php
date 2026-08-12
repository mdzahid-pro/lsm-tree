<?php

declare(strict_types=1);

namespace Lsm\Segment;

use ArrayIterator;
use Generator;
use Iterator;
use IteratorIterator;
use Lsm\Contract\SegmentInterface;
use Lsm\Model\Entry;
use Traversable;

/**
 * Merges several sorted runs into one sorted stream.
 *
 * The merge is streaming: it holds one entry per input run, not one entry per
 * key in the level. Compacting a level of ten million entries therefore costs
 * a handful of objects rather than ten million, which is the difference
 * between a background job and an out-of-memory crash.
 *
 * Conflicts are settled by sequence number — the highest wins. That single
 * rule is what makes overwrites and deletes work; there is no second one.
 */
final readonly class SegmentMerger
{
    /**
     * @param list<SegmentInterface> $segments
     * @param bool                   $dropTombstones only ever true when the
     *                                               result lands on the bottom
     *                                               level, where nothing older
     *                                               can be shadowed
     *
     * @return Generator<Entry> ascending by key
     */
    public function merge(array $segments, bool $dropTombstones): Generator
    {
        $cursors = [];

        foreach ($segments as $segment) {
            $cursor = self::cursor($segment->entries());
            $cursor->rewind();

            if ($cursor->valid()) {
                $cursors[] = $cursor;
            }
        }

        while ($cursors !== []) {
            $smallest = null;

            foreach ($cursors as $cursor) {
                $key = $cursor->current()->key;

                if ($smallest === null || strcmp($key, $smallest) < 0) {
                    $smallest = $key;
                }
            }

            $winner = null;

            foreach ($cursors as $index => $cursor) {
                while ($cursor->valid() && $cursor->current()->key === $smallest) {
                    $candidate = $cursor->current();

                    if ($winner === null || $candidate->sequence > $winner->sequence) {
                        $winner = $candidate;
                    }

                    $cursor->next();
                }

                if (!$cursor->valid()) {
                    unset($cursors[$index]);
                }
            }

            $cursors = array_values($cursors);

            if ($winner === null) {
                continue;
            }

            if ($dropTombstones && $winner->isTombstone()) {
                continue;
            }

            yield $winner;
        }
    }

    /**
     * A linear scan across cursors beats a heap here: the number of inputs is
     * bounded by the compaction policy and is typically three to five, where
     * the constant factor of a heap costs more than it saves.
     *
     * @param iterable<Entry> $entries
     *
     * @return Iterator<Entry>
     */
    private static function cursor(iterable $entries): Iterator
    {
        if (is_array($entries)) {
            return new ArrayIterator($entries);
        }

        if ($entries instanceof Iterator) {
            return $entries;
        }

        /** @var Traversable<Entry> $entries */
        return new IteratorIterator($entries);
    }
}
