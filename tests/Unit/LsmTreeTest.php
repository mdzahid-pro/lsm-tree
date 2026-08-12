<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Config\EngineConfiguration;
use Lsm\Exception\KeyNotFoundException;
use Lsm\Filter\BloomFilterFactory;
use Lsm\LsmTree;
use Lsm\Runtime\EngineFactory;
use Lsm\Segment\SegmentFactory;
use Lsm\Sequence\SequentialSegmentIdGenerator;
use Lsm\Storage\InMemorySegmentStore;
use Lsm\Trace\CollectingTraceListener;
use Lsm\Wal\InMemoryWriteAheadLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The engine exercised entirely in memory. No database, no disk, no framework
 * — which is the whole reason every collaborator is an interface.
 */
final class LsmTreeTest extends TestCase
{
    #[Test]
    public function a_value_survives_the_flush_that_moves_it_to_a_run(): void
    {
        $tree = $this->tree(buffer: 2);

        $tree->put('a', 'one');
        $tree->put('b', 'two');
        $tree->put('c', 'three');

        self::assertSame('one', $tree->get('a'));
        self::assertSame('three', $tree->get('c'));
        self::assertGreaterThan(0, $tree->statistics()->runs);
    }

    #[Test]
    public function the_newer_write_wins_even_across_levels(): void
    {
        $tree = $this->tree(buffer: 2);

        $tree->put('a', 'old');
        $tree->put('filler', 'x');
        $tree->put('a', 'new');
        $tree->put('more', 'y');

        self::assertSame('new', $tree->get('a'));
    }

    #[Test]
    public function a_deleted_key_reads_as_absent(): void
    {
        $tree = $this->tree(buffer: 4);

        $tree->put('a', 'one');
        $tree->delete('a');

        self::assertNull($tree->get('a'));
        self::assertFalse($tree->has('a'));
    }

    /**
     * The resurrection test. A tombstone must outlive every older copy of its
     * key, which means surviving each merge until the bottom level collects
     * it. If compaction discards it early, the old value comes back.
     */
    #[Test]
    public function a_deleted_key_stays_deleted_through_compaction(): void
    {
        $tree = $this->tree(buffer: 2, maxRuns: 2, bottomLevel: 1);

        $tree->put('victim', 'original');
        $tree->put('a', '1');
        $tree->delete('victim');
        $tree->put('b', '2');
        $tree->put('c', '3');
        $tree->put('d', '4');
        $tree->flush();
        $tree->compact();

        self::assertNull($tree->get('victim'), 'A deleted key came back after compaction.');
    }

    /**
     * Compaction stores one run, not two.
     *
     * The older assertions here only checked that runs existed, which a
     * duplicated run satisfies just as well as a correct one. These count.
     */
    #[Test]
    public function compaction_leaves_one_copy_of_the_merged_run(): void
    {
        $tree = $this->tree(buffer: 2);

        // Buffer of two over ten writes seals five runs, which is enough to
        // trip the default four-runs-per-level threshold.
        foreach (range(1, 10) as $i) {
            $tree->put('k' . $i, 'v' . $i);
        }

        $ids = [];

        foreach ($tree->levels() as $runs) {
            foreach ($runs as $run) {
                $ids[] = $run->id();
            }
        }

        self::assertSame(
            $ids,
            array_values(array_unique($ids)),
            'A merged run was stored more than once.',
        );
    }

    #[Test]
    public function a_merged_level_holds_a_single_run(): void
    {
        $tree = $this->tree(buffer: 2, maxRuns: 2, bottomLevel: 2);

        foreach (range(1, 4) as $i) {
            $tree->put('k' . $i, 'v' . $i);
        }

        $tree->flush();

        self::assertCount(1, $tree->levels()[1] ?? [], 'A merge must produce exactly one run.');
    }

    /**
     * Two runs per level is the lowest value the policy accepts. A duplicated
     * merge output holds such a level at the threshold forever, so this hangs
     * rather than fails when the store is wrong.
     */
    #[Test]
    public function compaction_terminates_at_the_smallest_legal_threshold(): void
    {
        $tree = $this->tree(buffer: 2, maxRuns: 2, bottomLevel: 1);

        foreach (range(1, 8) as $i) {
            $tree->put('k' . $i, 'v' . $i);
        }

        $tree->flush();
        $tree->compact();

        foreach ($tree->levels() as $level => $runs) {
            self::assertLessThan(2, count($runs), "Level {$level} is still at the compaction threshold.");
        }
    }

    #[Test]
    public function reported_run_count_matches_the_runs_actually_stored(): void
    {
        $tree = $this->tree(buffer: 2, maxRuns: 2, bottomLevel: 1);

        foreach (range(1, 8) as $i) {
            $tree->put('k' . $i, 'v' . $i);
        }

        $tree->flush();
        $tree->compact();

        $stored = 0;

        foreach ($tree->levels() as $runs) {
            $stored += count($runs);
        }

        self::assertSame($stored, $tree->statistics()->runs);
    }

    #[Test]
    public function a_key_that_was_never_written_is_absent(): void
    {
        self::assertNull($this->tree()->get('nothing'));
    }

    #[Test]
    public function get_or_fail_throws_for_a_missing_key(): void
    {
        $this->expectException(KeyNotFoundException::class);

        $this->tree()->getOrFail('nothing');
    }

    #[Test]
    public function get_or_fail_throws_for_a_deleted_key(): void
    {
        $tree = $this->tree();
        $tree->put('a', 'one');
        $tree->delete('a');

        $this->expectException(KeyNotFoundException::class);

        $tree->getOrFail('a');
    }

    #[Test]
    public function the_write_ahead_log_is_truncated_once_its_writes_are_durable(): void
    {
        $wal = new InMemoryWriteAheadLog;
        $tree = $this->tree(buffer: 2, wal: $wal);

        $tree->put('a', '1');
        self::assertSame(1, $wal->count());

        $tree->put('b', '2');
        self::assertSame(0, $wal->count(), 'The log should be cleared by the flush it protected.');
    }

    #[Test]
    public function recovery_replays_the_log_and_moves_the_sequence_past_it(): void
    {
        $wal = new InMemoryWriteAheadLog;
        $crashed = $this->tree(buffer: 100, wal: $wal);
        $crashed->put('a', 'buffered');

        $restarted = $this->tree(buffer: 100, wal: $wal);

        self::assertSame(1, $restarted->recover());
        self::assertSame('buffered', $restarted->get('a'));

        // The replayed entry carried sequence 1, so the next write must be
        // higher or the merge rule cannot tell them apart.
        $restarted->put('a', 'newer');
        self::assertSame('newer', $restarted->get('a'));
        self::assertGreaterThan(1, $restarted->statistics()->sequence);
    }

    #[Test]
    public function statistics_describe_the_shape_of_the_tree(): void
    {
        $tree = $this->tree(buffer: 2);
        $tree->put('a', '1');

        $statistics = $tree->statistics();

        self::assertSame(1, $statistics->buffered);
        self::assertSame(2, $statistics->bufferCapacity);
        self::assertSame($statistics->runs, $statistics->readAmplification());
    }

    #[Test]
    public function every_structural_event_is_reported_to_the_listener(): void
    {
        $trace = new CollectingTraceListener;
        $tree = $this->tree(buffer: 2, trace: $trace);

        $tree->put('a', '1');
        $tree->put('b', '2');

        self::assertNotSame([], $trace->events());
    }

    private function tree(
        int $buffer = 8,
        int $maxRuns = 4,
        int $bottomLevel = 2,
        ?InMemoryWriteAheadLog $wal = null,
        ?CollectingTraceListener $trace = null,
    ): LsmTree {
        $filters = new BloomFilterFactory(10, 7);

        return (new EngineFactory)->create(
            new EngineConfiguration($buffer, $maxRuns, $bottomLevel),
            new InMemorySegmentStore(
                new SegmentFactory(new SequentialSegmentIdGenerator, $filters),
            ),
            $wal ?? new InMemoryWriteAheadLog,
            $trace,
        );
    }
}
