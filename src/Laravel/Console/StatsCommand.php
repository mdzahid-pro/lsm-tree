<?php

declare(strict_types=1);

namespace Lsm\Laravel\Console;

use Illuminate\Console\Command;
use Lsm\Laravel\Console\Concerns\ResolvesStore;
use Lsm\Laravel\LsmManager;

/**
 * The command an operator runs when something feels slow.
 *
 * Read amplification is the number to watch: it is how many runs a miss has to
 * consult, and a steady climb means compaction is not keeping up with writes.
 */
final class StatsCommand extends Command
{
    use ResolvesStore;

    protected $signature = 'lsm:stats
                            {--store= : The store to inspect, defaulting to the configured one}
                            {--all : Inspect every configured store}
                            {--json : Emit machine-readable output}';

    protected $description = 'Show the shape and health of an LSM store';

    public function handle(LsmManager $manager): int
    {
        $payload = [];

        foreach ($this->targetStores($manager) as $name) {
            $statistics = $manager->store($name)->statistics();
            $payload[$name] = $statistics->toArray();

            if ((bool) $this->option('json')) {
                continue;
            }

            $this->components->twoColumnDetail(
                sprintf('<fg=cyan>%s</>', $name),
                sprintf('%d run(s) across %d level(s)', $statistics->runs, $statistics->levels()),
            );

            $rows = [];

            foreach ($statistics->runsPerLevel as $level => $runs) {
                $rows[] = ['L' . $level, $runs];
            }

            if ($rows !== []) {
                $this->table(['Level', 'Runs'], $rows);
            }

            $this->components->twoColumnDetail('Buffered', sprintf(
                '%d / %d entries',
                $statistics->buffered,
                $statistics->bufferCapacity,
            ));
            $this->components->twoColumnDetail('Write-ahead log', sprintf('%d entry(s)', $statistics->walEntries));
            $this->components->twoColumnDetail('Sequence', (string) $statistics->sequence);
            $this->components->twoColumnDetail('Read amplification', (string) $statistics->readAmplification());
            $this->newLine();
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
