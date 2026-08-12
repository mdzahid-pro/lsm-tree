<?php

declare(strict_types=1);

namespace Lsm\Exception;

use RuntimeException;

final class LockTimeoutException extends RuntimeException implements LsmExceptionInterface
{
    public static function forStore(string $store, int $seconds): self
    {
        return new self(sprintf(
            'Could not acquire the maintenance lock for the "%s" store within %d second(s). '
            . 'Another process is flushing or compacting it.',
            $store,
            $seconds,
        ));
    }
}
