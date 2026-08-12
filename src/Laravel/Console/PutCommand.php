<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;

final class PutCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:put {key : The key to write} {value : The value to store}
                            {--store= : The store to write to}';

    protected $description = 'Write a single key to an LSM store';

    public function handle(LsmManager $manager): int
    {
        /** @var string $key */
        $key = $this->argument('key');
        /** @var string $value */
        $value = $this->argument('value');

        $this->resolveStore($manager)->put($key, $value);

        $this->components->info(sprintf('Wrote [%s].', $key));

        return self::SUCCESS;
    }
}
