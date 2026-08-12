<?php

declare(strict_types=1);

namespace Lsm\Filter;

use Lsm\Contract\KeyFilterBuilderInterface;
use Lsm\Contract\KeyFilterInterface;

final class NullKeyFilterBuilder implements KeyFilterBuilderInterface
{
    public function add(string $key): void
    {
    }

    public function build(): KeyFilterInterface
    {
        return new NullKeyFilter();
    }
}
