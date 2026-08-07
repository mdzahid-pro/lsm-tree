<?php

declare(strict_types=1);

namespace Lsm\Exception;

use RuntimeException;

final class MalformedOperationException extends RuntimeException implements LsmExceptionInterface
{
    public static function atLine(string $path, int $line, string $reason): self
    {
        return new self(sprintf('%s line %d: %s', $path, $line, $reason));
    }

    public static function unknownType(string $type): self
    {
        return new self(sprintf('Unknown operation type "%s". Expected put, delete or get.', $type));
    }
}
