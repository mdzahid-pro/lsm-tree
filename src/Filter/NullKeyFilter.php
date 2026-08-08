<?php

declare(strict_types=1);

namespace Lsm\Filter;

use Lsm\Contract\KeyFilterInterface;

/**
 * Always answers "maybe". Turning filters off becomes a configuration choice
 * instead of a branch inside the read path.
 */
final readonly class NullKeyFilter implements KeyFilterInterface
{
    public function mightContain(string $key): bool
    {
        return true;
    }
}
