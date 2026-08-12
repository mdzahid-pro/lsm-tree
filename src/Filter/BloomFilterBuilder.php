<?php

declare(strict_types=1);

namespace Lsm\Filter;

use Lsm\Contract\KeyFilterBuilderInterface;
use Lsm\Contract\KeyFilterInterface;

final class BloomFilterBuilder implements KeyFilterBuilderInterface
{
    private string $bits;

    public function __construct(
        private readonly int $size,
        private readonly int $hashCount,
    ) {
        $this->bits = str_repeat("\0", intdiv($size + 7, 8));
    }

    public function add(string $key): void
    {
        foreach (BloomFilter::positions($key, $this->size, $this->hashCount) as $position) {
            $index = intdiv($position, 8);
            $this->bits[$index] = chr(ord($this->bits[$index]) | (1 << ($position % 8)));
        }
    }

    public function build(): KeyFilterInterface
    {
        return new BloomFilter($this->bits, $this->size, $this->hashCount);
    }
}
