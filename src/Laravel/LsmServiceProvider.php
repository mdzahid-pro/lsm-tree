<?php

declare(strict_types=1);

namespace Lsm\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Lsm\Contract\KeyValueStoreInterface;
use Lsm\Contract\MaintenanceInterface;
use Lsm\Laravel\Console\CompactCommand;
use Lsm\Laravel\Console\FlushCommand;
use Lsm\Laravel\Console\ForgetCommand;
use Lsm\Laravel\Console\GetCommand;
use Lsm\Laravel\Console\ImportCommand;
use Lsm\Laravel\Console\InstallCommand;
use Lsm\Laravel\Console\PruneCommand;
use Lsm\Laravel\Console\PutCommand;
use Lsm\Laravel\Console\StatsCommand;
use Lsm\LsmTree;

/**
 * The package's only entry point into the framework.
 *
 * Everything the package binds is resolved lazily through closures, so an
 * application that never touches a store never builds one. The provider is
 * deliberately not deferred: deferred providers are loaded only when one of
 * their bindings is resolved, which would leave the artisan commands
 * undiscoverable until something else had already used the package.
 */
final class LsmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'lsm');

        $this->app->singleton(
            StoreFactory::class,
            static fn (Container $app): StoreFactory => new StoreFactory($app),
        );

        $this->app->singleton(LsmManager::class, static fn (Container $app): LsmManager => new LsmManager(
            $app,
            $app->make(Config::class),
            $app->make(StoreFactory::class),
        ));

        // Type-hinting the narrow contracts in application code is the point of
        // the whole design, so both resolve to the default store out of the box.
        $this->app->bind(
            LsmTree::class,
            static fn (Container $app): LsmTree => $app->make(LsmManager::class)->store(),
        );

        foreach ([KeyValueStoreInterface::class, MaintenanceInterface::class] as $contract) {
            $this->app->bind($contract, static fn (Container $app): LsmTree => $app->make(LsmTree::class));
        }

        $this->app->alias(LsmManager::class, 'lsm');
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([$this->configPath() => $this->app->configPath('lsm.php')], ['lsm', 'lsm-config']);
        $this->publishes(
            [$this->migrationsPath() => $this->app->databasePath('migrations')],
            ['lsm', 'lsm-migrations'],
        );

        $this->commands([
            InstallCommand::class,
            StatsCommand::class,
            FlushCommand::class,
            CompactCommand::class,
            PruneCommand::class,
            ImportCommand::class,
            GetCommand::class,
            PutCommand::class,
            ForgetCommand::class,
        ]);
    }

    private function configPath(): string
    {
        return dirname(__DIR__, 2) . '/config/lsm.php';
    }

    private function migrationsPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }
}
