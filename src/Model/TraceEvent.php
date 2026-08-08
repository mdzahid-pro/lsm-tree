<?php

declare(strict_types=1);

namespace Lsm\Model;

use Lsm\Contract\SegmentInterface;

/**
 * A single observable moment inside the engine.
 *
 * Named constructors keep the engine readable at the call site and keep the
 * message wording in one place.
 */
final readonly class TraceEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public TraceEventType $type,
        public string $message,
        public array $context = [],
    ) {}

    public static function write(Entry $entry): self
    {
        return new self(
            TraceEventType::Write,
            $entry->isTombstone()
                ? sprintf('tombstone %s buffered (seq %d)', $entry->key, $entry->sequence)
                : sprintf('%s = %s buffered (seq %d)', $entry->key, $entry->value, $entry->sequence),
            ['key' => $entry->key, 'sequence' => $entry->sequence],
        );
    }

    public static function flush(SegmentInterface $segment): self
    {
        return new self(
            TraceEventType::Flush,
            sprintf(
                'mem-table sealed as %s at L%d (%d entries, %s..%s)',
                $segment->id(),
                $segment->level(),
                $segment->count(),
                $segment->minKey(),
                $segment->maxKey(),
            ),
            [
                'segment' => $segment->id(),
                'level' => $segment->level(),
                'entries' => $segment->count(),
            ],
        );
    }

    public static function compaction(CompactionPlan $plan, ?SegmentInterface $result): self
    {
        return new self(
            TraceEventType::Compaction,
            sprintf(
                'merged %s into %s at L%d',
                implode(' + ', $plan->inputIds()),
                $result?->id() ?? 'nothing (all entries discarded)',
                $plan->targetLevel,
            ),
            [
                'segment' => $result?->id(),
                'level' => $plan->targetLevel,
                'inputs' => $plan->inputIds(),
                'dropped_tombstones' => $plan->dropTombstones,
            ],
        );
    }

    public static function readHit(string $key, string $location): self
    {
        return new self(
            TraceEventType::ReadHit,
            sprintf('%s found in %s', $key, $location),
            ['key' => $key, 'location' => $location],
        );
    }

    public static function readMiss(string $key): self
    {
        return new self(TraceEventType::ReadMiss, sprintf('%s not present', $key), ['key' => $key]);
    }

    public static function filterSkip(string $key, SegmentInterface $segment): self
    {
        return new self(
            TraceEventType::FilterSkip,
            sprintf('%s skipped for %s — filter says absent', $key, $segment->id()),
            ['key' => $key, 'segment' => $segment->id()],
        );
    }

    public static function filterFalsePositive(string $key, SegmentInterface $segment): self
    {
        return new self(
            TraceEventType::FilterFalsePositive,
            sprintf('%s missing from %s — filter false positive', $key, $segment->id()),
            ['key' => $key, 'segment' => $segment->id()],
        );
    }
}
