<?php

declare(strict_types=1);

namespace Lsm\Exception;

use OutOfBoundsException;

final class KeyNotFoundException extends OutOfBoundsException implements LsmExceptionInterface
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Key "%s" is not present in the store.', $key));
    }
}
