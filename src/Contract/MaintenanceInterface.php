<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Model\Statistics;

/**
 * Operational control of a running engine.
 *
 * Kept apart from KeyValueStoreInterface so that request code sees only reads
 * and writes, while schedulers, queue workers and console commands get the
 * levers they actually need.
 */
interface MaintenanceInterface
{
    /**
     * Seals the buffer into a run. A no-op when the buffer is empty.
     */
    public function flush(): void;

    /**
     * Applies the compaction policy until it is satisfied.
     */
    public function compact(): void;

    /**
     * Replays the write-ahead log into the buffer after an unclean shutdown.
     *
     * @return int the number of entries restored
     */
    public function recover(): int;

    public function statistics(): Statistics;
}
