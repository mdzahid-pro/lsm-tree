<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Exception\SegmentNotFoundException;
use Lsm\Filter\BloomFilterFactory;
use Lsm\Model\Entry;
use Lsm\Segment\SegmentFactory;
use Lsm\Sequence\SequentialSegmentIdGenerator;
use Lsm\Storage\InMemorySegmentStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The memory driver's half of the replace() contract.
 *
 * write() stores the run; replace() only retires the inputs. This store used to
 * store the replacement a second time, which left every merged run duplicated
 * and could pin a level at the threshold its policy compacts on forever.
 */
final class InMemorySegmentStoreTest extends TestCase
{
    #[Test]
    public function replace_does_not_store_the_replacement_a_second_time(): void
    {
        $store = $this->store();

        $first = $store->write($this->entries('a', 'b'), 0, 2);
        $second = $store->write($this->entries('c', 'd'), 0, 2);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $merged = $store->write($this->entries('a', 'b', 'c', 'd'), 1, 4);
        self::assertNotNull($merged);

        $store->replace([$first, $second], $merged);

        $runs = $store->level(1);
        self::assertCount(1, $runs, 'write() already stored the merged run; replace() must not store it again.');
        self::assertSame($merged->id(), $runs[0]->id());
    }

    #[Test]
    public function replace_retires_the_obsolete_runs(): void
    {
        $store = $this->store();

        $first = $store->write($this->entries('a'), 0, 1);
        $second = $store->write($this->entries('b'), 0, 1);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $merged = $store->write($this->entries('a', 'b'), 1, 2);
        $store->replace([$first, $second], $merged);

        self::assertSame([], $store->level(0));
    }

    #[Test]
    public function replace_with_no_replacement_only_removes_the_inputs(): void
    {
        $store = $this->store();

        $only = $store->write($this->entries('a'), 0, 1);
        self::assertNotNull($only);

        $store->replace([$only], null);

        self::assertSame([], $store->level(0));
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function replacing_an_untracked_run_is_rejected(): void
    {
        $store = $this->store();
        $stray = $this->store()->write($this->entries('a'), 0, 1);
        self::assertNotNull($stray);

        $this->expectException(SegmentNotFoundException::class);

        $store->replace([$stray], null);
    }

    #[Test]
    public function count_matches_the_number_of_distinct_stored_runs(): void
    {
        $store = $this->store();

        $first = $store->write($this->entries('a'), 0, 1);
        $second = $store->write($this->entries('b'), 0, 1);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $merged = $store->write($this->entries('a', 'b'), 1, 2);
        $store->replace([$first, $second], $merged);

        $ids = [];

        foreach ($store->levels() as $runs) {
            foreach ($runs as $run) {
                $ids[] = $run->id();
            }
        }

        self::assertSame($ids, array_values(array_unique($ids)), 'A run must not appear twice in the hierarchy.');
        self::assertSame(count($ids), $store->count());
    }

    private function store(): InMemorySegmentStore
    {
        return new InMemorySegmentStore(
            new SegmentFactory(new SequentialSegmentIdGenerator, new BloomFilterFactory(10, 7)),
        );
    }

    /**
     * @return list<Entry>
     */
    private function entries(string ...$keys): array
    {
        $entries = [];
        $sequence = 1;

        foreach ($keys as $key) {
            $entries[] = new Entry($key, 'value-' . $key, $sequence++);
        }

        return $entries;
    }
}
