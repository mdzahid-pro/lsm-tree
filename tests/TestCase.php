<?php

declare(strict_types=1);

namespace Lsm\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Lsm\Laravel\LsmServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        if ($this->app instanceof Application) {
            $root = sys_get_temp_dir() . '/lsm-tests';

            foreach ((array) glob($root . '/*/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LsmServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('cache.default', 'array');

        $config->set('lsm.default', 'testing');
        $config->set('lsm.stores', [
            'testing' => self::storeConfig(),
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
    }

    /**
     * A deliberately tiny buffer and a shallow tree, so that a handful of
     * writes is enough to exercise flushing, compaction and tombstone
     * collection rather than just filling a buffer.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function storeConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'segments' => ['driver' => 'memory'],
            'wal' => ['driver' => 'memory'],
            'recover_on_boot' => false,
            'memtable' => ['max_entries' => 3],
            'compaction' => ['max_runs_per_level' => 2, 'bottom_level' => 1],
            'filter' => ['enabled' => true, 'bits_per_key' => 10, 'hashes' => null],
            'lock' => ['enabled' => false],
            'events' => false,
            'logging' => ['enabled' => false],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function defineStore(string $name, array $config): void
    {
        /** @var Repository $repository */
        $repository = $this->app->make('config');
        $repository->set('lsm.stores.' . $name, self::storeConfig($config));
    }
}
