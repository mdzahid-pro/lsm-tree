<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;

final class GetCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:get {key : The key to read}
                            {--store= : The store to read from}';

    protected $description = 'Read a single key from an LSM store';

    public function handle(LsmManager $manager): int
    {
        /** @var string $key */
        $key = $this->argument('key');

        $value = $this->resolveStore($manager)->get($key);

        if ($value === null) {
            $this->components->warn(sprintf('[%s] is not present.', $key));

            return self::FAILURE;
        }

        $this->line($value);

        return self::SUCCESS;
    }
}
