<?php

declare(strict_types=1);

namespace Lsm\Segment;

use Lsm\Contract\KeyFilterFactoryInterface;
use Lsm\Contract\SegmentIdGeneratorInterface;
use Lsm\Model\Entry;
use Lsm\Model\Segment;

/**
 * Assembles an in-memory run: name it, collect its entries, build its filter.
 *
 * Used by stores that keep runs resident. Stores that stream runs to disk or
 * to a database drive the id generator and the filter builder themselves and
 * never construct a Segment at all.
 */
final readonly class SegmentFactory
{
    public function __construct(
        private SegmentIdGeneratorInterface $ids,
        private KeyFilterFactoryInterface $filters,
    ) {}

    /**
     * @param iterable<Entry> $entries ascending by key, one entry per key
     * @param int|null $estimatedCount an upper bound used to size the
     *                                 filter before the stream is read
     */
    public function create(iterable $entries, int $level, ?int $estimatedCount = null): ?Segment
    {
        $collected = [];

        foreach ($entries as $entry) {
            $collected[] = $entry;
        }

        if ($collected === []) {
            return null;
        }

        $builder = $this->filters->builder($estimatedCount ?? count($collected));

        foreach ($collected as $entry) {
            $builder->add($entry->key);
        }

        return new Segment($this->ids->next(), $level, $collected, $builder->build());
    }
}
