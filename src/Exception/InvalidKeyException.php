<?php

declare(strict_types=1);

namespace Lsm\Exception;

use InvalidArgumentException;

final class InvalidKeyException extends InvalidArgumentException implements LsmExceptionInterface
{
    public static function empty(): self
    {
        return new self('A record key must be a non-empty string.');
    }
}
