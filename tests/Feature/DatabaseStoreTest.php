<?php

declare(strict_types=1);

namespace Lsm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lsm\Laravel\Facades\Lsm;
use Lsm\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DatabaseStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function writes_survive_a_flush_into_rows(): void
    {
        Lsm::put('a', 'one');
        Lsm::put('b', 'two');
        Lsm::put('c', 'three');

        self::assertDatabaseCount('lsm_segments', 1);
        self::assertSame('one', Lsm::get('a'));
        self::assertSame('three', Lsm::get('c'));
    }

    #[Test]
    public function compaction_replaces_rows_atomically(): void
    {
        foreach (range(1, 12) as $i) {
            Lsm::put('k' . $i, 'v' . $i);
        }

        Lsm::flush();
        Lsm::compact();

        // Every entry still readable, and no run left orphaned behind.
        self::assertSame('v1', Lsm::get('k1'));
        self::assertSame('v12', Lsm::get('k12'));
        self::assertSame(
            Lsm::statistics()->runs,
            (int) $this->app->make('db')->table('lsm_segments')->count(),
        );
    }

    #[Test]
    public function a_deleted_key_stays_deleted_after_compaction(): void
    {
        foreach (range(1, 6) as $i) {
            Lsm::put('k' . $i, 'v' . $i);
        }

        Lsm::delete('k2');

        foreach (range(7, 12) as $i) {
            Lsm::put('k' . $i, 'v' . $i);
        }

        Lsm::flush();
        Lsm::compact();

        self::assertNull(Lsm::get('k2'));
    }

    /**
     * A fresh process must not restart the sequence at zero: reusing numbers
     * makes the merge rule pick arbitrarily between two versions of a key.
     */
    #[Test]
    public function a_new_instance_resumes_the_sequence_from_the_stored_runs(): void
    {
        Lsm::put('a', 'first');
        Lsm::put('b', 'second');
        Lsm::put('c', 'third');
        Lsm::flush();

        $sequence = Lsm::statistics()->sequence;
        self::assertGreaterThan(0, $sequence);

        Lsm::purge('testing');

        self::assertGreaterThanOrEqual($sequence, Lsm::statistics()->sequence);

        Lsm::put('a', 'updated');
        Lsm::flush();
        Lsm::compact();

        self::assertSame('updated', Lsm::get('a'));
    }

    #[Test]
    public function the_write_ahead_log_is_emptied_by_the_flush_it_protected(): void
    {
        Lsm::put('a', 'one');

        self::assertDatabaseCount('lsm_wal', 1);

        Lsm::put('b', 'two');
        Lsm::put('c', 'three');

        self::assertDatabaseCount('lsm_wal', 0);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('lsm.stores.testing', self::storeConfig([
            'segments' => ['driver' => 'database'],
            'wal' => ['driver' => 'database'],
        ]));
    }
}
