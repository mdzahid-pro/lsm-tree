<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

final class InstallCommand extends Command
{
    protected $signature = 'lsm:install {--force : Overwrite an existing configuration file}';

    protected $description = 'Publish the LSM configuration and migrations';

    public function handle(): int
    {
        $this->components->info('Publishing the LSM configuration.');

        $this->callSilently('vendor:publish', [
            '--tag' => 'lsm-config',
            '--force' => (bool) $this->option('force'),
        ]);

        if ($this->needsMigrations()) {
            $this->callSilently('vendor:publish', ['--tag' => 'lsm-migrations']);
            $this->components->info('Published the migrations. Run "php artisan migrate" when you are ready.');
        }

        $this->newLine();
        $this->components->bulletList([
            'Configure your stores in config/lsm.php',
            'Enable the maintenance lock if more than one process writes to a store',
            'Schedule "lsm:compact --all" so reads stay fast',
        ]);

        return self::SUCCESS;
    }

    private function needsMigrations(): bool
    {
        if (!$this->input->isInteractive()) {
            return true;
        }

        return confirm('Publish the database migrations? Required only for the database driver.', true);
    }
}
