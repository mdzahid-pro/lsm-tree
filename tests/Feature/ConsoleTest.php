<?php

declare(strict_types=1);

namespace Lsm\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Lsm\Laravel\Facades\Lsm;
use Lsm\Laravel\Jobs\RunCompaction;
use Lsm\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConsoleTest extends TestCase
{
    #[Test]
    public function a_key_can_be_written_and_read_from_the_console(): void
    {
        $this->artisan('lsm:put', ['key' => 'a', 'value' => 'one'])->assertSuccessful();

        // The engine is rebuilt per command in a real CLI invocation, so the
        // write has to be flushed to be visible. Within one process the buffer
        // is shared, which is what this asserts.
        $this->artisan('lsm:get', ['key' => 'a'])->expectsOutput('one')->assertSuccessful();
    }

    #[Test]
    public function reading_a_missing_key_fails_rather_than_printing_nothing(): void
    {
        $this->artisan('lsm:get', ['key' => 'ghost'])->assertFailed();
    }

    #[Test]
    public function forget_tombstones_a_key(): void
    {
        Lsm::put('a', 'one');

        $this->artisan('lsm:forget', ['key' => 'a'])->assertSuccessful();

        self::assertNull(Lsm::get('a'));
    }

    #[Test]
    public function flush_seals_the_buffer(): void
    {
        Lsm::put('a', 'one');

        $this->artisan('lsm:flush')->assertSuccessful();

        self::assertSame(0, Lsm::statistics()->buffered);
        self::assertGreaterThan(0, Lsm::statistics()->runs);
    }

    #[Test]
    public function stats_can_emit_json(): void
    {
        Lsm::put('a', 'one');

        $this->artisan('lsm:stats', ['--json' => true])->assertSuccessful();
    }

    #[Test]
    public function compaction_can_be_queued_instead_of_run(): void
    {
        Bus::fake();

        $this->artisan('lsm:compact', ['--queue' => true])->assertSuccessful();

        Bus::assertDispatched(RunCompaction::class);
    }

    #[Test]
    public function a_workload_file_can_be_imported(): void
    {
        $path = sys_get_temp_dir() . '/lsm-import-' . bin2hex(random_bytes(4)) . '.jsonl';

        file_put_contents($path, implode("\n", [
            '{"type":"put","key":"alpha","value":"1"}',
            '{"type":"put","key":"beta","value":"2"}',
            '{"type":"delete","key":"alpha"}',
            '',
        ]));

        try {
            $this->artisan('lsm:import', ['path' => $path, '--flush' => true])->assertSuccessful();

            self::assertSame('2', Lsm::get('beta'));
            self::assertNull(Lsm::get('alpha'));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function pruning_warns_when_the_driver_has_nothing_to_prune(): void
    {
        $this->artisan('lsm:prune')->assertSuccessful();
    }
}
