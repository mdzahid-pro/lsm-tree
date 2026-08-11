<?php

declare(strict_types=1);

namespace Lsm\Tests\Feature;

use Lsm\Contract\KeyValueStoreInterface;
use Lsm\Contract\MaintenanceInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Laravel\Exceptions\StoreNotConfiguredException;
use Lsm\Laravel\Exceptions\UnsupportedDriverException;
use Lsm\Laravel\Facades\Lsm;
use Lsm\Laravel\LsmManager;
use Lsm\Segment\SegmentFactory;
use Lsm\Sequence\SequentialSegmentIdGenerator;
use Lsm\Storage\InMemorySegmentStore;
use Lsm\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class StoreResolutionTest extends TestCase
{
    #[Test]
    public function the_facade_reads_and_writes_the_default_store(): void
    {
        Lsm::put('a', 'one');

        self::assertSame('one', Lsm::get('a'));
        self::assertTrue(Lsm::has('a'));

        Lsm::delete('a');

        self::assertNull(Lsm::get('a'));
    }

    #[Test]
    public function named_stores_are_isolated_from_each_other(): void
    {
        $this->defineStore('secondary', []);

        Lsm::store('testing')->put('shared', 'primary value');
        Lsm::store('secondary')->put('shared', 'secondary value');

        self::assertSame('primary value', Lsm::store('testing')->get('shared'));
        self::assertSame('secondary value', Lsm::store('secondary')->get('shared'));
    }

    #[Test]
    public function a_resolved_store_is_reused_within_a_request(): void
    {
        self::assertSame(Lsm::store('testing'), Lsm::store('testing'));
    }

    #[Test]
    public function purging_forces_a_rebuild(): void
    {
        $first = Lsm::store('testing');
        Lsm::purge('testing');

        self::assertNotSame($first, Lsm::store('testing'));
    }

    #[Test]
    public function an_unconfigured_store_names_the_ones_that_exist(): void
    {
        $this->expectException(StoreNotConfiguredException::class);
        $this->expectExceptionMessageMatches('/testing/');

        Lsm::store('nope')->get('a');
    }

    #[Test]
    public function an_unknown_driver_lists_what_is_available(): void
    {
        $this->defineStore('broken', ['segments' => ['driver' => 'quantum']]);

        $this->expectException(UnsupportedDriverException::class);
        $this->expectExceptionMessageMatches('/memory, file, database/');

        Lsm::store('broken')->get('a');
    }

    /**
     * The extension point that makes the package worth building on: a driver
     * the package has never heard of, registered from an application's own
     * service provider.
     */
    #[Test]
    public function a_custom_segment_driver_can_be_registered(): void
    {
        $this->app->make(LsmManager::class)->extendSegments(
            'custom',
            static fn ($app, string $name, array $config, $filters): SegmentStoreInterface => new InMemorySegmentStore(
                new SegmentFactory(new SequentialSegmentIdGenerator('custom-'), $filters),
            ),
        );

        $this->defineStore('bespoke', ['segments' => ['driver' => 'custom']]);

        $store = Lsm::store('bespoke');
        $store->put('a', 'one');
        $store->put('b', 'two');
        $store->put('c', 'three');
        $store->flush();

        self::assertSame('one', $store->get('a'));
        self::assertStringStartsWith('custom-', $store->levels()[0][0]->id());
    }

    #[Test]
    public function the_narrow_contracts_resolve_from_the_container(): void
    {
        $reader = $this->app->make(KeyValueStoreInterface::class);
        $maintenance = $this->app->make(MaintenanceInterface::class);

        $reader->put('a', 'one');
        $maintenance->flush();

        self::assertSame('one', $reader->get('a'));
        self::assertSame(0, $maintenance->statistics()->buffered);
    }
}
