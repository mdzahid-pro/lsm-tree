<?php

declare(strict_types=1);

namespace Lsm\Storage;

use JsonException;
use Lsm\Contract\KeyFilterFactoryInterface;
use Lsm\Contract\SegmentIdGeneratorInterface;
use Lsm\Contract\SegmentInterface;
use Lsm\Contract\SegmentStoreInterface;
use Lsm\Exception\InvalidConfigurationException;
use Lsm\Exception\SegmentNotFoundException;
use Lsm\Exception\UnreadableSourceException;
use Lsm\Filter\BloomFilter;
use Lsm\Filter\NullKeyFilter;
use Lsm\Model\Entry;
use Throwable;

/**
 * Keeps runs as sorted files in a directory, with a manifest naming the ones
 * that count.
 *
 * A run is written to a temporary name and renamed into place, and the
 * manifest is replaced the same way. Because rename is atomic on a local file
 * system, a crash at any point leaves either the old hierarchy or the new one,
 * never a mixture. Files left behind by an interrupted write are invisible to
 * readers — the manifest never mentions them — and are removed by prune().
 *
 * @phpstan-type SegmentMeta array{level: int, count: int, min: string, max: string, sequence: int}
 *
 * This driver requires a real local path. Object stores such as S3 have no
 * atomic rename and no cheap seek; use the database driver there, or write an
 * adapter against SegmentStoreInterface.
 */
final class FileSegmentStore implements SegmentStoreInterface
{
    private const string MANIFEST = 'manifest.json';

    /** @var array<int, list<SegmentInterface>>|null */
    private ?array $cachedLevels = null;

    /** @var array<string, array{filter: \Lsm\Contract\KeyFilterInterface, index: list<array{0: string, 1: int}>}> */
    private array $sidecars = [];

    public function __construct(
        private readonly string $root,
        private readonly SegmentIdGeneratorInterface $ids,
        private readonly KeyFilterFactoryInterface $filters,
        private readonly int $indexInterval = 64,
    ) {
        if ($indexInterval < 1) {
            throw InvalidConfigurationException::invalidValue('storage.index_interval', 'at least 1');
        }

        if (!is_dir($this->root) && !@mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw InvalidConfigurationException::invalidValue('storage.path', 'a writable directory');
        }
    }

    /**
     * @param iterable<Entry> $entries ascending by key, one entry per key
     */
    public function write(iterable $entries, int $level, ?int $estimatedCount = null): ?SegmentInterface
    {
        $id = $this->ids->next();
        $temporary = $this->path($id . '.tmp');
        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            throw UnreadableSourceException::missingFile($temporary);
        }

        $filter = $this->filters->builder($estimatedCount ?? 0);
        $index = [];
        $offset = 0;
        $count = 0;
        $minKey = null;
        $maxKey = null;
        $maxSequence = 0;

        try {
            foreach ($entries as $entry) {
                if ($count % $this->indexInterval === 0) {
                    $index[] = [$entry->key, $offset];
                }

                $line = FileSegment::encode($entry);
                fwrite($handle, $line);

                $offset += strlen($line);
                $minKey ??= $entry->key;
                $maxKey = $entry->key;
                $maxSequence = max($maxSequence, $entry->sequence);
                $count++;

                $filter->add($entry->key);
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }

        fclose($handle);

        if ($count === 0) {
            @unlink($temporary);

            return null;
        }

        $built = $filter->build();

        $this->writeJson($this->path($id . '.meta.json'), [
            'filter' => $built instanceof BloomFilter
                ? ['bits' => base64_encode($built->bits()), 'size' => $built->size(), 'hashes' => $built->hashCount()]
                : null,
            'index' => $index,
        ]);

        if (!@rename($temporary, $this->path($id . '.jsonl'))) {
            @unlink($temporary);

            throw UnreadableSourceException::missingFile($this->path($id . '.jsonl'));
        }

        $manifest = $this->manifest();
        $manifest['segments'] = [$id => [
            'level' => $level,
            'count' => $count,
            'min' => $minKey,
            'max' => $maxKey,
            'sequence' => $maxSequence,
        ]] + $manifest['segments'];

        $this->commit($manifest);

        return $this->hydrate($id, $manifest['segments'][$id]);
    }

    public function replace(array $obsolete, ?SegmentInterface $replacement): void
    {
        $manifest = $this->manifest();

        foreach ($obsolete as $segment) {
            if (!isset($manifest['segments'][$segment->id()])) {
                throw SegmentNotFoundException::forId($segment->id());
            }

            unset($manifest['segments'][$segment->id()]);
        }

        $this->commit($manifest);

        // Only once the manifest no longer references them can the files go.
        foreach ($obsolete as $segment) {
            @unlink($this->path($segment->id() . '.jsonl'));
            @unlink($this->path($segment->id() . '.meta.json'));
            unset($this->sidecars[$segment->id()]);
        }
    }

    public function levels(): array
    {
        if ($this->cachedLevels !== null) {
            return $this->cachedLevels;
        }

        $levels = [];

        foreach ($this->manifest()['segments'] as $id => $meta) {
            $levels[(int) $meta['level']][] = $this->hydrate((string) $id, $meta);
        }

        ksort($levels, SORT_NUMERIC);

        return $this->cachedLevels = $levels;
    }

    public function level(int $level): array
    {
        return $this->levels()[$level] ?? [];
    }

    public function count(): int
    {
        return count($this->manifest()['segments']);
    }

    /**
     * There is no cross-file transaction here. The manifest swap is atomic, so
     * the hierarchy is never observed in a broken state, but a crash between
     * writing a run and committing the manifest leaves an unreferenced file
     * behind rather than rolling the write back. prune() cleans those up.
     */
    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function highestSequence(): int
    {
        $highest = 0;

        foreach ($this->manifest()['segments'] as $meta) {
            $highest = max($highest, (int) $meta['sequence']);
        }

        return $highest;
    }

    /**
     * Deletes files the manifest does not reference.
     *
     * @return int the number of files removed
     */
    public function prune(): int
    {
        $known = array_keys($this->manifest()['segments']);
        $removed = 0;

        foreach ((array) glob($this->root . '/*') as $file) {
            if (!is_string($file) || basename($file) === self::MANIFEST) {
                continue;
            }

            $id = preg_replace('/\.(tmp|jsonl|meta\.json)$/', '', basename($file));

            if ($id !== null && !in_array($id, $known, true) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function refresh(): void
    {
        $this->cachedLevels = null;
        $this->sidecars = [];
    }

    /**
     * @param SegmentMeta $meta
     */
    private function hydrate(string $id, array $meta): FileSegment
    {
        return new FileSegment(
            $id,
            (int) $meta['level'],
            (int) $meta['count'],
            (string) $meta['min'],
            (string) $meta['max'],
            $this->path($id . '.jsonl'),
            fn (string $segmentId): array => $this->sidecar($segmentId),
        );
    }

    /**
     * @return array{filter: \Lsm\Contract\KeyFilterInterface, index: list<array{0: string, 1: int}>}
     */
    private function sidecar(string $id): array
    {
        if (isset($this->sidecars[$id])) {
            return $this->sidecars[$id];
        }

        $data = $this->readJson($this->path($id . '.meta.json'));
        $filter = $data['filter'] ?? null;
        $bits = is_array($filter) ? base64_decode((string) $filter['bits'], true) : false;

        /** @var list<array{0: string, 1: int}> $index */
        $index = is_array($data['index'] ?? null) ? $data['index'] : [];

        return $this->sidecars[$id] = [
            'filter' => $bits === false || !is_array($filter)
                ? new NullKeyFilter()
                : new BloomFilter($bits, (int) $filter['size'], (int) $filter['hashes']),
            'index' => $index,
        ];
    }

    /**
     * @return array{segments: array<string, SegmentMeta>}
     */
    private function manifest(): array
    {
        $data = $this->readJson($this->path(self::MANIFEST));

        /** @var array<string, SegmentMeta> $segments */
        $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];

        return ['segments' => $segments];
    }

    /**
     * @param array{segments: array<string, SegmentMeta>} $manifest
     */
    private function commit(array $manifest): void
    {
        $this->writeJson($this->path(self::MANIFEST), $manifest);
        $this->cachedLevels = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($temporary, $encoded, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw UnreadableSourceException::missingFile($path);
        }
    }

    private function path(string $file): string
    {
        return rtrim($this->root, '/') . '/' . $file;
    }
}
