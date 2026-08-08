<?php

declare(strict_types=1);

namespace Lsm\Filter;

use Lsm\Contract\KeyFilterBuilderInterface;
use Lsm\Contract\KeyFilterFactoryInterface;

/**
 * Filtering turned off. Every run is then searched on every miss, which is
 * the honest baseline to measure the Bloom filter against.
 */
final readonly class NullKeyFilterFactory implements KeyFilterFactoryInterface
{
    public function builder(int $estimatedKeys): KeyFilterBuilderInterface
    {
        return new NullKeyFilterBuilder;
    }
}
