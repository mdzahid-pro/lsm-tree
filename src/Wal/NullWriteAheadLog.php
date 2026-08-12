<?php

declare(strict_types=1);

namespace Lsm\Wal;

use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Model\Entry;

/**
 * Durability turned off.
 *
 * Legitimate when the store is a cache that can be rebuilt, and a data-loss
 * bug waiting to happen anywhere else. Chosen explicitly in configuration so
 * that the trade-off is visible in code review.
 */
final class NullWriteAheadLog implements WriteAheadLogInterface
{
    public function append(Entry $entry): void
    {
    }

    public function replay(): array
    {
        return [];
    }

    public function truncate(): void
    {
    }

    public function count(): int
    {
        return 0;
    }
}
