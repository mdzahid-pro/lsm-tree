<?php

declare(strict_types=1);

namespace Lsm\Laravel\Exceptions;

use InvalidArgumentException;
use Lsm\Exception\LsmExceptionInterface;

final class StoreNotConfiguredException extends InvalidArgumentException implements LsmExceptionInterface
{
    /**
     * @param list<string> $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'The LSM store [%s] is not defined in config/lsm.php. Configured stores: %s.',
            $name,
            $configured === [] ? 'none' : implode(', ', $configured),
        ));
    }
}
