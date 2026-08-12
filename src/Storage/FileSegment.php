<?php

declare(strict_types=1);

namespace Lsm\Storage;

use Closure;
use Generator;
use Lsm\Contract\KeyFilterInterface;
use Lsm\Contract\SegmentInterface;
use Lsm\Exception\UnreadableSourceException;
use Lsm\Filter\NullKeyFilter;
use Lsm\Model\Entry;

/**
 * A run held in one sorted file, read by seeking.
 *
 * Alongside the data file sits a sidecar holding the run's filter and a sparse
 * index — one key/offset pair every few dozen entries. A lookup binary-searches
 * that index in memory, seeks straight to the block that could contain the key
 * and scans only that block. Reading a single key out of a million-entry run
 * therefore touches a few kilobytes of disk, not the whole file.
 *
 * The sidecar is loaded on first use and never on construction, so listing the
 * hierarchy stays cheap even when it holds hundreds of runs.
 */
final class FileSegment implements SegmentInterface
{
    /** @var array{filter: KeyFilterInterface, index: list<array{0: string, 1: int}>}|null */
    private ?array $sidecar = null;

    /**
     * @param Closure(string): array{filter: KeyFilterInterface, index: list<array{0: string, 1: int}>} $loadSidecar
     */
    public function __construct(
        private readonly string $id,
        private readonly int $level,
        private readonly int $count,
        private readonly string $minKey,
        private readonly string $maxKey,
        private readonly string $path,
        private readonly Closure $loadSidecar,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function minKey(): string
    {
        return $this->minKey;
    }

    public function maxKey(): string
    {
        return $this->maxKey;
    }

    public function mightContain(string $key): bool
    {
        if (strcmp($key, $this->minKey) < 0 || strcmp($key, $this->maxKey) > 0) {
            return false;
        }

        return $this->filter()->mightContain($key);
    }

    public function get(string $key): ?Entry
    {
        $handle = $this->open();

        try {
            fseek($handle, $this->blockOffset($key));

            while (($line = fgets($handle)) !== false) {
                $entry = self::decode($line);

                if ($entry === null) {
                    continue;
                }

                $comparison = strcmp($entry->key, $key);

                if ($comparison === 0) {
                    return $entry;
                }

                // The file is sorted, so the first key past the target proves
                // the target is absent. Without this the scan would run to the
                // end of the block for every miss.
                if ($comparison > 0) {
                    return null;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * @return Generator<Entry>
     */
    public function entries(): iterable
    {
        $handle = $this->open();

        try {
            while (($line = fgets($handle)) !== false) {
                $entry = self::decode($line);

                if ($entry !== null) {
                    yield $entry;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function filter(): KeyFilterInterface
    {
        return $this->sidecar()['filter'];
    }

    /**
     * The offset of the last indexed key that is not greater than the target.
     * Zero when the target precedes every indexed key.
     */
    private function blockOffset(string $key): int
    {
        $index = $this->sidecar()['index'];

        if ($index === []) {
            return 0;
        }

        $low = 0;
        $high = count($index) - 1;
        $offset = 0;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if (strcmp($index[$middle][0], $key) <= 0) {
                $offset = $index[$middle][1];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $offset;
    }

    /**
     * @return array{filter: KeyFilterInterface, index: list<array{0: string, 1: int}>}
     */
    private function sidecar(): array
    {
        return $this->sidecar ??= ($this->loadSidecar)($this->id);
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw UnreadableSourceException::missingFile($this->path);
        }

        return $handle;
    }

    public static function decode(string $line): ?Entry
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        /** @var array{k?: string, v?: string|null, s?: int}|null $data */
        $data = json_decode($line, true);

        if (!is_array($data) || !isset($data['k'], $data['s'])) {
            return null;
        }

        return new Entry((string) $data['k'], $data['v'] ?? null, (int) $data['s']);
    }

    public static function encode(Entry $entry): string
    {
        return json_encode(
            ['k' => $entry->key, 'v' => $entry->value, 's' => $entry->sequence],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /**
     * @return array{filter: KeyFilterInterface, index: list<array{0: string, 1: int}>}
     */
    public static function emptySidecar(): array
    {
        return ['filter' => new NullKeyFilter(), 'index' => []];
    }
}
