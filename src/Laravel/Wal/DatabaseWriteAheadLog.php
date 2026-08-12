<?php

declare(strict_types=1);

namespace Lsm\Laravel\Wal;

use Illuminate\Database\ConnectionInterface;
use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Model\Entry;

/**
 * Durability through a table.
 *
 * Slower per write than a file, and the right choice whenever more than one
 * machine serves the same store: a log on a local disk is invisible to the
 * worker that has to recover it.
 */
final class DatabaseWriteAheadLog implements WriteAheadLogInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $store = 'default',
        private readonly string $table = 'lsm_wal',
    ) {
    }

    public function append(Entry $entry): void
    {
        $this->connection->table($this->table)->insert([
            'store' => $this->store,
            'entry_key' => $entry->key,
            'entry_value' => $entry->value,
            'sequence' => $entry->sequence,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function replay(): array
    {
        /** @var list<object{entry_key: string, entry_value: string|null, sequence: int}> $rows */
        $rows = $this->connection->table($this->table)
            ->where('store', $this->store)
            ->orderBy('id')
            ->get(['entry_key', 'entry_value', 'sequence'])
            ->all();

        return array_map(
            static fn (object $row): Entry => new Entry($row->entry_key, $row->entry_value, (int) $row->sequence),
            $rows,
        );
    }

    public function truncate(): void
    {
        $this->connection->table($this->table)->where('store', $this->store)->delete();
    }

    public function count(): int
    {
        return $this->connection->table($this->table)->where('store', $this->store)->count();
    }
}
