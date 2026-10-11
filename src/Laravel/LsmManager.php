<?php

declare(strict_types=1);

namespace Lsm\Laravel;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Lsm\Contract\KeyFilterFactoryInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Laravel\Exceptions\StoreNotConfiguredException;
use Lsm\LsmTree;
use Lsm\Model\Statistics;

/**
 * Resolves named stores and keeps them for the life of the request.
 *
 * Modelled on Laravel's own cache and database managers, so the mental model
 * transfers: a default store, any number of named ones, and an extension point
 * for drivers the package does not ship.
 *
 * @method void put(string $key, string $value)
 * @method void delete(string $key)
 * @method string|null get(string $key)
 * @method string getOrFail(string $key)
 * @method bool has(string $key)
 * @method void flush()
 * @method void compact()
 * @method int recover()
 * @method Statistics statistics()
 *
 * @phpstan-type SegmentConfig array<string, mixed>
 * @phpstan-type SegmentDriver Closure(Container, string, SegmentConfig, KeyFilterFactoryInterface): SegmentStoreInterface
 * @phpstan-type WalDriver Closure(Container, string, array<string, mixed>): WriteAheadLogInterface
 */
final class LsmManager
{
    /** @var array<string, LsmTree> */
    private array $resolved = [];

    /** @var array<string, SegmentDriver> */
    private array $segmentDrivers = [];

    /** @var array<string, WalDriver> */
    private array $walDrivers = [];

    public function __construct(
        private readonly Config $config,
        private readonly StoreFactory $factory,
    ) {}

    /**
     * Forwards to the default store so that Lsm::get('k') reads naturally.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->store()->{$method}(...$arguments);
    }

    public function store(?string $name = null): LsmTree
    {
        $name ??= $this->getDefaultStore();

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function getDefaultStore(): string
    {
        return (string) $this->config->get('lsm.default', 'default');
    }

    public function setDefaultStore(string $name): void
    {
        $this->config->set('lsm.default', $name);
    }

    /**
     * @return list<string>
     */
    public function stores(): array
    {
        /** @var array<string, mixed> $stores */
        $stores = $this->config->get('lsm.stores', []);

        return array_values(array_map(strval(...), array_keys($stores)));
    }

    /**
     * Registers a segment store driver.
     *
     * The closure receives the container, the store name, the "segments"
     * section of its configuration and the filter factory the engine will use,
     * and must return a SegmentStoreInterface.
     *
     * @param SegmentDriver $resolver
     */
    public function extendSegments(string $driver, Closure $resolver): self
    {
        $this->segmentDrivers[$driver] = $resolver;

        return $this;
    }

    /**
     * @param WalDriver $resolver
     */
    public function extendWal(string $driver, Closure $resolver): self
    {
        $this->walDrivers[$driver] = $resolver;

        return $this;
    }

    /**
     * Drops a resolved instance so the next call rebuilds it.
     *
     * A long-running worker should purge after another process may have
     * compacted the same store; otherwise it keeps a cached view of a
     * hierarchy that has moved on.
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->resolved = [];

            return;
        }

        unset($this->resolved[$name]);
    }

    /**
     * @return array<string, LsmTree>
     */
    public function resolvedStores(): array
    {
        return $this->resolved;
    }

    private function resolve(string $name): LsmTree
    {
        /** @var array<string, mixed>|null $config */
        $config = $this->config->get('lsm.stores.' . $name);

        if (!is_array($config)) {
            throw StoreNotConfiguredException::named($name, $this->stores());
        }

        return $this->factory->make($name, $config, $this->segmentDrivers, $this->walDrivers);
    }
}
