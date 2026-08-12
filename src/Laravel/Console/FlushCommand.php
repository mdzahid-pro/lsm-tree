<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;

final class FlushCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:flush
                            {--store= : The store to flush, defaulting to the configured one}
                            {--all : Flush every configured store}';

    protected $description = 'Seal the in-memory buffer of an LSM store into a run';

    public function handle(LsmManager $manager): int
    {
        foreach ($this->targetStores($manager) as $name) {
            $store = $manager->store($name);
            $buffered = $store->statistics()->buffered;

            $store->flush();

            $this->components->info(sprintf('Flushed %d buffered entry(s) from [%s].', $buffered, $name));
        }

        return self::SUCCESS;
    }
}
