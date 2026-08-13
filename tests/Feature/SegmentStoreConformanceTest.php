<?php

declare(strict_types=1);

namespace Lsm\Tests\Feature;

use Illuminate\Database\ConnectionInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Exception\SegmentNotFoundException;
use Lsm\Filter\BloomFilterFactory;
use Lsm\Laravel\Storage\DatabaseSegmentStore;
use Lsm\Model\Entry;
use Lsm\Segment\SegmentFactory;
use Lsm\Sequence\SequentialSegmentIdGenerator;
use Lsm\Sequence\UniqueSegmentIdGenerator;
use Lsm\Storage\FileSegmentStore;
use Lsm\Storage\InMemorySegmentStore;
use Lsm\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * One contract, three drivers, one set of expectations.
 *
 * The drivers previously disagreed about whether replace() stores the
 * replacement — two ignored it, one stored it, and nothing detected the split
 * because each driver was only ever tested against itself. Every shared rule in
 * SegmentStoreInterface belongs here so a fourth driver cannot quietly diverge.
 *
 * The database driver needs a booted application, which is why this lives under
 * Feature rather than Unit.
 */
final class SegmentStoreConformanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/lsm-tests/conformance-' . bin2hex(random_bytes(6));

        if (!is_dir($this->root)) {
            mkdir($this->root, 0o777, true);
        }
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function drivers(): array
    {
        return [
            'memory' => ['memory'],
            'file' => ['file'],
            'database' => ['database'],
        ];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function replace_does_not_store_the_replacement_a_second_time(string $driver): void
    {
        $store = $this->store($driver);

        $first = $store->write($this->entries('a', 'b'), 0, 2);
        $second = $store->write($this->entries('c', 'd'), 0, 2);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $merged = $store->write($this->entries('a', 'b', 'c', 'd'), 1, 4);
        self::assertNotNull($merged);

        $store->replace([$first, $second], $merged);

        self::assertCount(1, $store->level(1), "The {$driver} driver stored the merged run more than once.");
        self::assertSame([], $store->level(0), "The {$driver} driver did not retire the merged inputs.");
    }

    #[Test]
    #[DataProvider('drivers')]
    public function count_matches_the_runs_actually_stored(string $driver): void
    {
        $store = $this->store($driver);

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

        self::assertSame($ids, array_values(array_unique($ids)), "The {$driver} driver reports a run twice.");
        self::assertSame(count($ids), $store->count());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function levels_are_ordered_shallowest_first_and_newest_run_first(string $driver): void
    {
        $store = $this->store($driver);

        $older = $store->write($this->entries('a'), 0, 1);
        $newer = $store->write($this->entries('b'), 0, 1);
        $store->write($this->entries('c'), 1, 1);
        self::assertNotNull($older);
        self::assertNotNull($newer);

        $levels = $store->levels();

        self::assertSame([0, 1], array_keys($levels), "The {$driver} driver returned levels out of order.");
        self::assertSame(
            [$newer->id(), $older->id()],
            array_map(static fn (object $run): string => $run->id(), $levels[0]),
            "The {$driver} driver returned runs oldest-first within a level.",
        );
    }

    #[Test]
    #[DataProvider('drivers')]
    public function replacing_an_untracked_run_is_rejected(string $driver): void
    {
        $store = $this->store($driver);
        $stray = $this->store($driver)->write($this->entries('a'), 0, 1);
        self::assertNotNull($stray);

        $this->expectException(SegmentNotFoundException::class);

        $store->replace([$stray], null);
    }

    private function store(string $driver): SegmentStoreInterface
    {
        $filters = new BloomFilterFactory(10, 7);

        return match ($driver) {
            'memory' => new InMemorySegmentStore(
                new SegmentFactory(new SequentialSegmentIdGenerator, $filters),
            ),
            'file' => new FileSegmentStore(
                $this->root . '/' . bin2hex(random_bytes(4)),
                new UniqueSegmentIdGenerator,
                $filters,
                16,
            ),
            'database' => new DatabaseSegmentStore(
                $this->app->make(ConnectionInterface::class),
                new UniqueSegmentIdGenerator,
                $filters,
                'conformance-' . bin2hex(random_bytes(4)),
            ),
            default => self::fail("Unknown driver {$driver}."),
        };
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
