<?php

declare(strict_types=1);

namespace Lsm\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Lsm\Laravel\Events\CompactionCompleted;
use Lsm\Laravel\Events\MemTableFlushed;
use Lsm\Laravel\Facades\Lsm;
use Lsm\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EventsTest extends TestCase
{
    #[Test]
    public function sealing_the_buffer_dispatches_an_event(): void
    {
        Event::fake([MemTableFlushed::class, CompactionCompleted::class]);

        Lsm::put('a', 'one');
        Lsm::put('b', 'two');
        Lsm::put('c', 'three');

        Event::assertDispatched(
            MemTableFlushed::class,
            static fn (MemTableFlushed $event): bool => $event->store === 'testing'
                && $event->level === 0
                && $event->entryCount === 3,
        );
    }

    #[Test]
    public function compaction_reports_what_it_merged(): void
    {
        Event::fake([CompactionCompleted::class]);

        foreach (range(1, 9) as $i) {
            Lsm::put('k' . $i, 'v' . $i);
        }

        Event::assertDispatched(
            CompactionCompleted::class,
            static fn (CompactionCompleted $event): bool => $event->store === 'testing'
                && count($event->inputSegmentIds) >= 2
                && $event->resultSegmentId !== null,
        );
    }

    #[Test]
    public function nothing_is_dispatched_when_events_are_switched_off(): void
    {
        $this->defineStore('quiet', ['events' => false]);

        Event::fake([MemTableFlushed::class]);

        $store = Lsm::store('quiet');
        $store->put('a', 'one');
        $store->put('b', 'two');
        $store->put('c', 'three');

        Event::assertNotDispatched(MemTableFlushed::class);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('lsm.stores.testing', self::storeConfig(['events' => true]));
    }
}
