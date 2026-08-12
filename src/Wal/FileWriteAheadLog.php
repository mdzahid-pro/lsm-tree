<?php

declare(strict_types=1);

namespace Lsm\Wal;

use Lsm\Contract\WriteAheadLogInterface;
use Lsm\Exception\UnreadableSourceException;
use Lsm\Model\Entry;
use Lsm\Storage\FileSegment;

/**
 * An append-only log file.
 *
 * The handle is opened once and kept open, because reopening it per write
 * would make the log more expensive than the write it protects. Each append is
 * flushed to the OS; call fsync-level durability what it is — this survives a
 * process crash, not a power cut. Set sync to true to pay for the latter.
 */
final class FileWriteAheadLog implements WriteAheadLogInterface
{
    /** @var resource|null */
    private mixed $handle = null;

    private int $count = 0;

    public function __construct(
        private readonly string $path,
        private readonly bool $sync = false,
    ) {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw UnreadableSourceException::missingFile($directory);
        }

        $this->count = $this->replayCount();
    }

    public function append(Entry $entry): void
    {
        fwrite($this->open(), FileSegment::encode($entry));

        if ($this->sync) {
            fflush($this->open());
        }

        $this->count++;
    }

    public function replay(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return [];
        }

        $entries = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $entry = FileSegment::decode($line);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    public function truncate(): void
    {
        $this->close();

        if (is_file($this->path)) {
            @unlink($this->path);
        }

        $this->count = 0;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /**
     * @return resource
     */
    private function open()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        $handle = @fopen($this->path, 'ab');

        if ($handle === false) {
            throw UnreadableSourceException::missingFile($this->path);
        }

        return $this->handle = $handle;
    }

    private function replayCount(): int
    {
        return count($this->replay());
    }
}
