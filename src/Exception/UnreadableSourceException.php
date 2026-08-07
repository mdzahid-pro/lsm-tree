<?php

declare(strict_types=1);

namespace Lsm\Exception;

use RuntimeException;

final class UnreadableSourceException extends RuntimeException implements LsmExceptionInterface
{
    public static function missingFile(string $path): self
    {
        return new self(sprintf('Operation source "%s" does not exist or is not readable.', $path));
    }

    /**
     * @param list<string> $supported
     */
    public static function unsupportedFormat(string $path, array $supported): self
    {
        return new self(sprintf(
            'Cannot infer a reader for "%s". Supported extensions: %s.',
            $path,
            implode(', ', $supported),
        ));
    }
}
