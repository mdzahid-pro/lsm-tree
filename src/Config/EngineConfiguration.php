<?php

declare(strict_types=1);

namespace Lsm\Config;

use Lsm\Exception\InvalidConfigurationException;

/**
 * The knobs that shape the algorithm itself.
 *
 * Deliberately free of drivers, paths and connection names: those are the
 * host's business. Validation happens here, once, so that every class further
 * in may trust its inputs without defensive checks.
 */
final readonly class EngineConfiguration
{
    public function __construct(
        public int $memTableMaxEntries = 1000,
        public int $maxRunsPerLevel = 4,
        public int $bottomLevel = 2,
        public bool $filterEnabled = true,
        public int $filterBitsPerKey = 10,
        public ?int $filterHashes = null,
    ) {
        if ($memTableMaxEntries < 1) {
            throw InvalidConfigurationException::invalidValue('memtable.max_entries', 'at least 1');
        }

        if ($maxRunsPerLevel < 2) {
            throw InvalidConfigurationException::invalidValue('compaction.max_runs_per_level', 'at least 2');
        }

        if ($bottomLevel < 1) {
            throw InvalidConfigurationException::invalidValue('compaction.bottom_level', 'at least 1');
        }

        if ($filterBitsPerKey < 1) {
            throw InvalidConfigurationException::invalidValue('filter.bits_per_key', 'at least 1');
        }

        if ($filterHashes !== null && $filterHashes < 1) {
            throw InvalidConfigurationException::invalidValue('filter.hashes', 'at least 1, or null to derive it');
        }
    }

    /**
     * The number of hash functions that minimises the false-positive rate for
     * the configured bits per key: k = (m/n) * ln 2, rounded and clamped.
     *
     * Deriving it means an operator can tune one number instead of two and
     * still land on a sensible filter.
     */
    public function resolvedFilterHashes(): int
    {
        if ($this->filterHashes !== null) {
            return $this->filterHashes;
        }

        return max(1, min(16, (int) round($this->filterBitsPerKey * M_LN2)));
    }

    /**
     * @param array<string, mixed> $config a "stores.*" section of the Laravel
     *                                     configuration file
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, mixed> $memtable */
        $memtable = is_array($config['memtable'] ?? null) ? $config['memtable'] : [];
        /** @var array<string, mixed> $compaction */
        $compaction = is_array($config['compaction'] ?? null) ? $config['compaction'] : [];
        /** @var array<string, mixed> $filter */
        $filter = is_array($config['filter'] ?? null) ? $config['filter'] : [];

        $hashes = $filter['hashes'] ?? null;

        return new self(
            (int) ($memtable['max_entries'] ?? 1000),
            (int) ($compaction['max_runs_per_level'] ?? 4),
            (int) ($compaction['bottom_level'] ?? 2),
            (bool) ($filter['enabled'] ?? true),
            (int) ($filter['bits_per_key'] ?? 10),
            $hashes === null ? null : (int) $hashes,
        );
    }
}
