<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Lsm\Contract\OperationSourceInterface;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;
use Lsm\Laravel\Source\DiskOperationSource;
use Lsm\Model\Operation;
use Lsm\Parser\ParserFactory;
use Lsm\Runtime\OperationRunner;
use Lsm\Source\FileOperationSource;

/**
 * Bulk-loads a workload file: JSON Lines, NDJSON, CSV or TSV, from a local
 * path or any configured disk.
 *
 * The file is streamed, so importing a file larger than memory is normal
 * rather than heroic.
 */
final class ImportCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:import {path : Path to the workload file}
                            {--store= : The store to import into}
                            {--disk= : Read from this filesystem disk instead of the local path}
                            {--flush : Flush the buffer once the import finishes}';

    protected $description = 'Import put/delete/get operations from a file into an LSM store';

    public function handle(LsmManager $manager, FilesystemFactory $filesystems, ParserFactory $parsers): int
    {
        /** @var string $path */
        $path = $this->argument('path');
        /** @var string|null $disk */
        $disk = $this->option('disk');

        $parser = $parsers->forPath($path);

        $source = $disk === null
            ? new FileOperationSource($path, $parser)
            : new DiskOperationSource($filesystems->disk($disk), $path, $parser);

        $store = $this->resolveStore($manager);
        $bar = $this->output->createProgressBar();
        $bar->start();

        $summary = (new OperationRunner)->run(
            $source,
            $store,
            static function (int $index, Operation $operation) use ($bar): void {
                $bar->advance();
            },
        );

        $bar->finish();
        $this->newLine(2);

        if ((bool) $this->option('flush')) {
            $store->flush();
        }

        $this->components->twoColumnDetail('Source', $this->describe($source));
        $this->components->twoColumnDetail('Puts', (string) $summary->puts);
        $this->components->twoColumnDetail('Deletes', (string) $summary->deletes);
        $this->components->twoColumnDetail('Gets', (string) $summary->gets);
        $this->components->twoColumnDetail('Total', (string) $summary->total());

        return self::SUCCESS;
    }

    private function describe(OperationSourceInterface $source): string
    {
        return $source->describe();
    }
}
