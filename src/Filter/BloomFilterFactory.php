<?php

declare(strict_types=1);

namespace Lsm\Filter;

use Lsm\Contract\KeyFilterBuilderInterface;
use Lsm\Contract\KeyFilterFactoryInterface;
use Lsm\Exception\InvalidConfigurationException;

final readonly class BloomFilterFactory implements KeyFilterFactoryInterface
{
    public function __construct(
        private int $bitsPerKey = 10,
        private int $hashCount = 7,
        private int $minimumBits = 64,
    ) {
        if ($bitsPerKey < 1) {
            throw InvalidConfigurationException::invalidValue('filter.bits_per_key', 'at least 1');
        }

        if ($hashCount < 1) {
            throw InvalidConfigurationException::invalidValue('filter.hashes', 'at least 1');
        }
    }

    public function builder(int $estimatedKeys): KeyFilterBuilderInterface
    {
        $size = max($this->minimumBits, max(0, $estimatedKeys) * $this->bitsPerKey);

        return new BloomFilterBuilder($size, $this->hashCount);
    }
}
