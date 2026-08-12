<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Config\EngineConfiguration;
use Lsm\Filter\BloomFilterFactory;
use Lsm\Model\Entry;
use Lsm\Runtime\EngineFactory;
use Lsm\Sequence\UniqueSegmentIdGenerator;
use Lsm\Storage\FileSegmentStore;
use Lsm\Wal\FileWriteAheadLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the file driver against real files, because the things that break
 * a file-backed store — offsets, seeking, manifest ordering, restarting a
 * process — cannot be faked.
 */
final class FileSegmentStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/lsm-file-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function a_written_run_can_be_read_back_key_by_key(): void
    {
        $store = $this->store();

        $store->write($this->entries(200), 0, 200);

        $runs = $store->level(0);
        self::assertCount(1, $runs);

        $segment = $runs[0];
        self::assertSame(200, $segment->count());
        self::assertSame('key-000', $segment->minKey());
        self::assertSame('key-199', $segment->maxKey());

        // First, last and a middle key: the three places a sparse index and
        // its block scan are most likely to go wrong.
        self::assertSame('value-0', $segment->get('key-000')?->value);
        self::assertSame('value-99', $segment->get('key-099')?->value);
        self::assertSame('value-199', $segment->get('key-199')?->value);
        self::assertNull($segment->get('key-200'));
    }

    #[Test]
    public function a_run_streams_back_in_key_order(): void
    {
        $store = $this->store();
        $store->write($this->entries(150), 0, 150);

        $keys = [];

        foreach ($store->level(0)[0]->entries() as $entry) {
            $keys[] = $entry->key;
        }

        self::assertCount(150, $keys);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $keys);
    }

    #[Test]
    public function replacing_runs_removes_their_files(): void
    {
        $store = $this->store();
        $first = $store->write($this->entries(10), 0, 10);
        $second = $store->write($this->entries(10, 10), 0, 10);

        self::assertNotNull($first);
        self::assertNotNull($second);

        $merged = $store->write($this->entries(20), 1, 20);
        $store->replace([$first, $second], $merged);

        self::assertSame([], $store->level(0));
        self::assertCount(1, $store->level(1));
        self::assertFileDoesNotExist($this->root . '/' . $first->id() . '.jsonl');
    }

    #[Test]
    public function the_hierarchy_survives_a_restart(): void
    {
        $this->store()->write($this->entries(20), 0, 20);

        $restarted = $this->store();

        self::assertCount(1, $restarted->level(0));
        self::assertSame('value-5', $restarted->level(0)[0]->get('key-005')?->value);
        self::assertGreaterThan(0, $restarted->highestSequence());
    }

    #[Test]
    public function orphaned_files_are_invisible_to_readers_and_removed_by_pruning(): void
    {
        $store = $this->store();
        $store->write($this->entries(5), 0, 5);

        file_put_contents($this->root . '/seg_orphan.jsonl', "{}\n");

        self::assertCount(1, $store->level(0), 'An unreferenced file must not appear in the hierarchy.');
        self::assertSame(1, $store->prune());
        self::assertFileDoesNotExist($this->root . '/seg_orphan.jsonl');
    }

    #[Test]
    public function an_engine_backed_by_files_round_trips_writes_deletes_and_compaction(): void
    {
        $tree = (new EngineFactory())->create(
            new EngineConfiguration(memTableMaxEntries: 3, maxRunsPerLevel: 2, bottomLevel: 1),
            $this->store(),
            new FileWriteAheadLog($this->root . '/wal.jsonl'),
        );

        foreach (range(1, 12) as $i) {
            $tree->put('k' . $i, 'v' . $i);
        }

        $tree->delete('k4');
        $tree->flush();
        $tree->compact();

        self::assertSame('v1', $tree->get('k1'));
        self::assertSame('v12', $tree->get('k12'));
        self::assertNull($tree->get('k4'));
        self::assertNull($tree->get('missing'));
    }

    private function store(): FileSegmentStore
    {
        return new FileSegmentStore(
            $this->root,
            new UniqueSegmentIdGenerator(),
            new BloomFilterFactory(10, 7),
            16,
        );
    }

    /**
     * @return list<Entry>
     */
    private function entries(int $count, int $offset = 0): array
    {
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $index = $i + $offset;
            $entries[] = new Entry(sprintf('key-%03d', $index), 'value-' . $index, $index + 1);
        }

        return $entries;
    }
}
