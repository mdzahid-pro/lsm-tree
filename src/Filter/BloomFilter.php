<?php

declare(strict_types=1);

namespace Lsm\Filter;

use Lsm\Contract\KeyFilterInterface;

/**
 * A Bloom filter over a packed bit string.
 *
 * The bits live in a real byte string rather than an array of integers, which
 * makes a ten-bits-per-key filter for a million keys 1.25 MB instead of the
 * ~32 MB PHP would spend on a million-element int array. It is also directly
 * storable: a persistent segment keeps this string in a column and rebuilds
 * the filter without touching a single entry.
 *
 * Answers "definitely absent" or "possibly present". Never "definitely
 * present" — a false positive costs one wasted lookup and nothing more.
 */
final readonly class BloomFilter implements KeyFilterInterface
{
    public function __construct(
        private string $bits,
        private int $size,
        private int $hashCount,
    ) {
    }

    public function mightContain(string $key): bool
    {
        foreach (self::positions($key, $this->size, $this->hashCount) as $position) {
            $byte = ord($this->bits[intdiv($position, 8)] ?? "\0");

            if ((($byte >> ($position % 8)) & 1) === 0) {
                return false;
            }
        }

        return true;
    }

    public function bits(): string
    {
        return $this->bits;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function hashCount(): int
    {
        return $this->hashCount;
    }

    /**
     * Kirsch-Mitzenmacher double hashing: two real hashes stand in for k of
     * them, which is cheaper and trivial to reproduce in another language if
     * something other than PHP ever has to read these segments.
     *
     * @return list<int>
     */
    public static function positions(string $key, int $size, int $hashCount): array
    {
        $first = crc32($key);
        $second = crc32(strrev($key) . '#salt') | 1;

        $positions = [];

        for ($i = 0; $i < $hashCount; $i++) {
            $positions[] = (int) (($first + $i * $second) % $size);
        }

        return $positions;
    }
}
