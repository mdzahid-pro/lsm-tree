<?php

declare(strict_types=1);

namespace Lsm\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Lsm\Laravel\LsmManager;

/**
 * Compacts a store on a queue.
 *
 * Unique by store, because two workers compacting the same hierarchy at once
 * is exactly the situation the maintenance lock exists to prevent — and it is
 * cheaper to never enqueue the second job than to have it block on a lock.
 */
final class RunCompaction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $store = null) {}

    public function uniqueId(): string
    {
        return $this->store ?? 'default';
    }

    /**
     * Seconds after which the uniqueness lock is released even if the job
     * never finished, so a fatal error cannot block compaction forever.
     */
    public function uniqueFor(): int
    {
        return 900;
    }

    public function handle(LsmManager $manager): void
    {
        $store = $manager->store($this->store);
        $store->flush();
        $store->compact();
    }
}
