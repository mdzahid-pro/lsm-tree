<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Exception\KeyNotFoundException;

/**
 * The public face of the storage engine — reads and writes only.
 *
 * Application code depends on this narrow interface, never on LsmTree itself,
 * so the engine can be swapped for a hash map, a B-tree or a remote client
 * without touching a single caller. Maintenance lives in a separate contract
 * so that ordinary callers are not handed a compact() button.
 */
interface KeyValueStoreInterface
{
    public function put(string $key, string $value): void;

    /**
     * Records a tombstone. The key stops being readable immediately, while the
     * marker itself lives on until compaction reaches the bottom level.
     */
    public function delete(string $key): void;

    public function get(string $key): ?string;

    /**
     * @throws KeyNotFoundException when the key is absent or deleted
     */
    public function getOrFail(string $key): string;

    public function has(string $key): bool;
}
