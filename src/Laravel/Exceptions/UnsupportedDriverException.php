<?php

declare(strict_types=1);

namespace Lsm\Laravel\Exceptions;

use InvalidArgumentException;
use Lsm\Exception\LsmExceptionInterface;

final class UnsupportedDriverException extends InvalidArgumentException implements LsmExceptionInterface
{
    /**
     * @param list<string> $supported
     */
    public static function make(string $kind, string $driver, array $supported): self
    {
        return new self(sprintf(
            'Unsupported %s driver [%s]. Available: %s. Register your own with Lsm::extend%s().',
            $kind,
            $driver,
            implode(', ', $supported),
            ucfirst($kind),
        ));
    }
}
