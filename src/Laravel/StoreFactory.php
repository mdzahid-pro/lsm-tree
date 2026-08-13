<?php

declare(strict_types=1);

namespace Lsm\Laravel;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Lsm\Config\EngineConfiguration;
use Lsm\Contract\KeyFilterFactoryInterface;
use Lsm\Contract\LockInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Contract\TraceListenerInterface;
use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Filter\BloomFilterFactory;
use Lsm\Filter\NullKeyFilterFactory;
use Lsm\Laravel\Exceptions\UnsupportedDriverException;
use Lsm\Laravel\Lock\CacheLock;
use Lsm\Laravel\Storage\DatabaseSegmentStore;
use Lsm\Laravel\Trace\EventTraceListener;
use Lsm\Laravel\Trace\LogTraceListener;
use Lsm\Laravel\Wal\DatabaseWriteAheadLog;
use Lsm\Lock\NullLock;
use Lsm\LsmTree;
use Lsm\Runtime\EngineFactory;
use Lsm\Segment\SegmentFactory;
use Lsm\Sequence\SequentialSegmentIdGenerator;
use Lsm\Sequence\UniqueSegmentIdGenerator;
use Lsm\Storage\FileSegmentStore;
use Lsm\Storage\InMemorySegmentStore;
use Lsm\Trace\CompositeTraceListener;
use Lsm\Trace\NullTraceListener;
use Lsm\Wal\FileWriteAheadLog;
use Lsm\Wal\InMemoryWriteAheadLog;
use Lsm\Wal\NullWriteAheadLog;
use Psr\Log\LoggerInterface;

/**
 * Turns one entry of config/lsm.php into a running engine.
 *
 * All of the framework's opinions live here — connections, disks, cache locks,
 * log channels — so that everything below this line stays framework-agnostic
 * and unit-testable without booting an application.
 *
 * @phpstan-type SegmentConfig array<string, mixed>
 * @phpstan-type SegmentDriver Closure(Container, string, SegmentConfig, KeyFilterFactoryInterface): SegmentStoreInterface
 * @phpstan-type WalDriver Closure(Container, string, array<string, mixed>): WriteAheadLogInterface
 */
final readonly class StoreFactory
{
    public function __construct(private Container $container) {}

    /**
     * @param array<string, mixed> $config
     * @param array<string, SegmentDriver> $segmentDrivers
     * @param array<string, WalDriver> $walDrivers
     */
    public function make(string $name, array $config, array $segmentDrivers = [], array $walDrivers = []): LsmTree
    {
        $engine = EngineConfiguration::fromArray($config);
        $filters = $this->filters($engine);

        $tree = (new EngineFactory)->create(
            $engine,
            $this->segments($name, $config, $filters, $segmentDrivers),
            $this->wal($name, $config, $walDrivers),
            $this->trace($name, $config),
            $this->lock($name, $config),
        );

        if ((bool) ($config['recover_on_boot'] ?? false)) {
            $tree->recover();
        }

        return $tree;
    }

    private function filters(EngineConfiguration $engine): KeyFilterFactoryInterface
    {
        if (!$engine->filterEnabled) {
            return new NullKeyFilterFactory;
        }

        return new BloomFilterFactory($engine->filterBitsPerKey, $engine->resolvedFilterHashes());
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, SegmentDriver> $custom
     */
    private function segments(
        string $name,
        array $config,
        KeyFilterFactoryInterface $filters,
        array $custom,
    ): SegmentStoreInterface {
        /** @var array<string, mixed> $section */
        $section = is_array($config['segments'] ?? null) ? $config['segments'] : [];
        $driver = (string) ($section['driver'] ?? 'memory');

        if (isset($custom[$driver])) {
            return $custom[$driver]($this->container, $name, $section, $filters);
        }

        return match ($driver) {
            'memory' => new InMemorySegmentStore(
                new SegmentFactory(new SequentialSegmentIdGenerator, $filters),
            ),
            'file' => new FileSegmentStore(
                (string) ($section['path'] ?? sys_get_temp_dir() . '/lsm/' . $name),
                new UniqueSegmentIdGenerator,
                $filters,
                (int) ($section['index_interval'] ?? 64),
            ),
            'database' => new DatabaseSegmentStore(
                $this->connection($section),
                new UniqueSegmentIdGenerator,
                $filters,
                $name,
                (string) ($section['segments_table'] ?? 'lsm_segments'),
                (string) ($section['entries_table'] ?? 'lsm_entries'),
                (int) ($section['chunk_size'] ?? 1000),
            ),
            default => throw UnsupportedDriverException::make(
                'segments',
                $driver,
                array_merge(['memory', 'file', 'database'], array_keys($custom)),
            ),
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, WalDriver> $custom
     */
    private function wal(string $name, array $config, array $custom): WriteAheadLogInterface
    {
        /** @var array<string, mixed> $section */
        $section = is_array($config['wal'] ?? null) ? $config['wal'] : [];
        $driver = (string) ($section['driver'] ?? 'none');

        if (isset($custom[$driver])) {
            return $custom[$driver]($this->container, $name, $section);
        }

        return match ($driver) {
            'none' => new NullWriteAheadLog,
            'memory' => new InMemoryWriteAheadLog,
            'file' => new FileWriteAheadLog(
                (string) ($section['path'] ?? sys_get_temp_dir() . '/lsm/' . $name . '/wal.jsonl'),
                (bool) ($section['sync'] ?? false),
            ),
            'database' => new DatabaseWriteAheadLog(
                $this->connection($section),
                $name,
                (string) ($section['table'] ?? 'lsm_wal'),
            ),
            default => throw UnsupportedDriverException::make(
                'wal',
                $driver,
                array_merge(['none', 'memory', 'file', 'database'], array_keys($custom)),
            ),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function trace(string $name, array $config): TraceListenerInterface
    {
        $listeners = [];

        if ((bool) ($config['events'] ?? false)) {
            $listeners[] = new EventTraceListener($this->container->make(Dispatcher::class), $name);
        }

        /** @var array<string, mixed> $logging */
        $logging = is_array($config['logging'] ?? null) ? $config['logging'] : [];

        if ((bool) ($logging['enabled'] ?? false)) {
            $listeners[] = new LogTraceListener(
                $this->logger($logging['channel'] ?? null),
                $name,
                (string) ($logging['level'] ?? 'debug'),
                LogTraceListener::types(is_array($logging['types'] ?? null) ? $logging['types'] : []),
            );
        }

        if ($listeners === []) {
            return new NullTraceListener;
        }

        return new CompositeTraceListener(...$listeners);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function lock(string $name, array $config): LockInterface
    {
        /** @var array<string, mixed> $section */
        $section = is_array($config['lock'] ?? null) ? $config['lock'] : [];

        if (!(bool) ($section['enabled'] ?? false)) {
            return new NullLock;
        }

        $repository = $this->container->make(CacheFactory::class)->store(
            isset($section['store']) ? (string) $section['store'] : null,
        );

        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        if (!$store instanceof LockProvider) {
            throw UnsupportedDriverException::make(
                'lock',
                (string) ($section['store'] ?? 'default'),
                ['a cache store supporting atomic locks: redis, memcached, dynamodb, database'],
            );
        }

        return new CacheLock(
            $store,
            $name,
            (int) ($section['hold_seconds'] ?? 60),
            (int) ($section['wait_seconds'] ?? 10),
        );
    }

    /**
     * @param array<string, mixed> $section
     */
    private function connection(array $section): ConnectionInterface
    {
        return $this->container->make(DatabaseManager::class)->connection(
            isset($section['connection']) ? (string) $section['connection'] : null,
        );
    }

    private function logger(mixed $channel): LoggerInterface
    {
        /** @var mixed $logger */
        $logger = $this->container->make('log');

        if ($channel !== null && is_object($logger) && method_exists($logger, 'channel')) {
            /** @var mixed $logger */
            $logger = $logger->channel((string) $channel);
        }

        if (!$logger instanceof LoggerInterface) {
            return $this->container->make(LoggerInterface::class);
        }

        return $logger;
    }
}
