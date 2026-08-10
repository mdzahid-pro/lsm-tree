<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\Jobs\RunCompaction;
use Lsm\Laravel\LsmManager;

/**
 * The command to put on a schedule.
 *
 * Compaction is the maintenance that keeps reads fast; leaving it entirely to
 * the write path means whichever unlucky request fills the buffer also pays
 * for merging a level.
 */
final class CompactCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:compact
                            {--store= : The store to compact, defaulting to the configured one}
                            {--all : Compact every configured store}
                            {--queue : Dispatch the work to the queue instead of running it now}';

    protected $description = 'Merge accumulated runs in an LSM store';

    public function handle(LsmManager $manager): int
    {
        foreach ($this->targetStores($manager) as $name) {
            if ((bool) $this->option('queue')) {
                Bus::dispatch(new RunCompaction($name));
                $this->components->info(sprintf('Queued compaction for [%s].', $name));

                continue;
            }

            $store = $manager->store($name);
            $before = $store->statistics()->runs;

            $store->flush();
            $store->compact();

            $after = $store->statistics()->runs;

            $this->components->info(sprintf('Compacted [%s]: %d run(s) became %d.', $name, $before, $after));
        }

        return self::SUCCESS;
    }
}
