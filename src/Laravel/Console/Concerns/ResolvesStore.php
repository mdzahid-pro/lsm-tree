<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console\Concerns;

use Lsm\Laravel\LsmManager;
use Lsm\LsmTree;

/**
 * Shared plumbing for the console commands: which store, and which ones when
 * --all is given. Written once here rather than nine times.
 */
trait ResolvesStore
{
    protected function resolveStore(LsmManager $manager): LsmTree
    {
        /** @var string|null $name */
        $name = $this->option('store');

        return $manager->store($name);
    }

    protected function storeName(LsmManager $manager): string
    {
        /** @var string|null $name */
        $name = $this->option('store');

        return $name ?? $manager->getDefaultStore();
    }

    /**
     * @return list<string>
     */
    protected function targetStores(LsmManager $manager): array
    {
        if ((bool) $this->option('all')) {
            return $manager->stores();
        }

        return [$this->storeName($manager)];
    }
}
