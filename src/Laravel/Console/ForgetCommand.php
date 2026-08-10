<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;

/**
 * Writes a tombstone. The key stops being readable at once; the marker itself
 * survives until compaction reaches the bottom level, which is why the disk
 * does not shrink the moment you run this.
 */
final class ForgetCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:forget {key : The key to delete}
                            {--store= : The store to delete from}';

    protected $description = 'Delete a key from an LSM store';

    public function handle(LsmManager $manager): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        $this->resolveStore($manager)->delete($key);

        $this->components->info(sprintf('Tombstoned [%s].', $key));

        return self::SUCCESS;
    }
}
