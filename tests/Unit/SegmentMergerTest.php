<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Contract\SegmentInterface;
use Lsm\Filter\NullKeyFilter;
use Lsm\Model\Entry;
use Lsm\Model\Segment;
use Lsm\Segment\SegmentMerger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SegmentMergerTest extends TestCase
{
    #[Test]
    public function the_highest_sequence_number_wins(): void
    {
        $merged = $this->merge(false, [
            $this->segment('S2', [['a', 'new', 9], ['b', 'kept', 4]]),
            $this->segment('S1', [['a', 'old', 1], ['c', 'kept', 2]]),
        ]);

        self::assertSame(['a' => 'new', 'b' => 'kept', 'c' => 'kept'], $merged);
    }

    #[Test]
    public function the_output_is_sorted_regardless_of_input_order(): void
    {
        $merged = $this->merge(false, [
            $this->segment('S2', [['m', 'm', 5], ['z', 'z', 6]]),
            $this->segment('S1', [['a', 'a', 1], ['n', 'n', 2]]),
        ]);

        self::assertSame(['a', 'm', 'n', 'z'], array_keys($merged));
    }

    #[Test]
    public function a_tombstone_shadows_an_older_value_but_survives_the_merge(): void
    {
        $entries = iterator_to_array((new SegmentMerger())->merge([
            $this->segment('S2', [['a', null, 9]]),
            $this->segment('S1', [['a', 'old', 1]]),
        ], false), false);

        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->isTombstone());
    }

    #[Test]
    public function a_tombstone_is_discarded_only_when_dropping_is_allowed(): void
    {
        self::assertSame([], $this->merge(true, [
            $this->segment('S2', [['a', null, 9]]),
            $this->segment('S1', [['a', 'old', 1]]),
        ]));
    }

    #[Test]
    public function merging_reads_nothing_until_the_result_is_consumed(): void
    {
        $segment = $this->countingSegment();

        $merged = (new SegmentMerger())->merge([$segment], false);

        // A buffering merge would have walked the run by now. A streaming one
        // has not touched it, which is what keeps compaction's memory use
        // proportional to the number of runs rather than their size.
        self::assertSame(0, $segment->reads);

        iterator_to_array($merged, false);

        self::assertSame(2, $segment->reads);
    }

    /**
     * @param list<SegmentInterface> $segments
     *
     * @return array<string, string|null>
     */
    private function merge(bool $dropTombstones, array $segments): array
    {
        $result = [];

        foreach ((new SegmentMerger())->merge($segments, $dropTombstones) as $entry) {
            $result[$entry->key] = $entry->value;
        }

        return $result;
    }

    /**
     * @param list<array{0: string, 1: string|null, 2: int}> $rows
     */
    private function segment(string $id, array $rows): Segment
    {
        $entries = array_map(
            static fn (array $row): Entry => new Entry($row[0], $row[1], $row[2]),
            $rows,
        );

        return new Segment($id, 0, $entries, new NullKeyFilter());
    }

    /**
     * Deliberately returns the anonymous class rather than the interface, so
     * that the test can read the counter it exposes.
     */
    private function countingSegment()
    {
        return new class () implements SegmentInterface {
            public int $reads = 0;

            public function id(): string
            {
                return 'counting';
            }

            public function level(): int
            {
                return 0;
            }

            public function count(): int
            {
                return 2;
            }

            public function minKey(): string
            {
                return 'a';
            }

            public function maxKey(): string
            {
                return 'b';
            }

            public function mightContain(string $key): bool
            {
                return true;
            }

            public function get(string $key): ?Entry
            {
                return null;
            }

            public function entries(): iterable
            {
                $this->reads++;

                yield new Entry('a', 'a', 1);

                $this->reads++;

                yield new Entry('b', 'b', 2);
            }
        };
    }
}
