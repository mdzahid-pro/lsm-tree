<?php

declare(strict_types=1);

namespace Lsm\Wal;

use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Model\Entry;

/**
 * Durable in spirit only — it models the ordering the engine relies on and
 * keeps the demo free of stray files.
 */
final class InMemoryWriteAheadLog implements WriteAheadLogInterface
{
    /** @var list<Entry> */
    private array $records = [];

    public function append(Entry $entry): void
    {
        $this->records[] = $entry;
    }

    public function replay(): array
    {
        return $this->records;
    }

    public function truncate(): void
    {
        $this->records = [];
    }

    public function count(): int
    {
        return count($this->records);
    }
}
