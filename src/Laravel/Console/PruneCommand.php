<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;
use Lsm\Storage\FileSegmentStore;

/**
 * Removes segment files that no manifest references.
 *
 * These accumulate when a process dies between writing a run and committing
 * the manifest. They are harmless — no reader can see them — but they occupy
 * disk, so this belongs on a weekly schedule for file-backed stores.
 */
final class PruneCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:prune
                            {--store= : The store to prune, defaulting to the configured one}
                            {--all : Prune every configured store}';

    protected $description = 'Delete orphaned segment files left by interrupted writes';

    public function handle(LsmManager $manager): int
    {
        foreach ($this->targetStores($manager) as $name) {
            $segments = $manager->store($name)->segmentStore();

            if (!$segments instanceof FileSegmentStore) {
                $this->components->warn(sprintf('[%s] does not use the file driver — nothing to prune.', $name));

                continue;
            }

            $this->components->info(sprintf('Pruned %d orphaned file(s) from [%s].', $segments->prune(), $name));
        }

        return self::SUCCESS;
    }
}
