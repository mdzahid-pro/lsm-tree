<?php

declare(strict_types=1);

namespace Lsm\Exception;

use LogicException;

final class EmptySegmentException extends LogicException implements LsmExceptionInterface
{
    public static function create(): self
    {
        return new self('A segment must contain at least one entry.');
    }
}
