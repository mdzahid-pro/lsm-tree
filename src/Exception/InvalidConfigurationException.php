<?php

declare(strict_types=1);

namespace Lsm\Exception;

use InvalidArgumentException;

final class InvalidConfigurationException extends InvalidArgumentException implements LsmExceptionInterface
{
    public static function missingKey(string $key): self
    {
        return new self(sprintf('Configuration key "%s" is missing.', $key));
    }

    public static function invalidValue(string $key, string $expected): self
    {
        return new self(sprintf('Configuration key "%s" must be %s.', $key, $expected));
    }

    /**
     * @param list<string> $supported
     */
    public static function unknownDriver(string $key, string $driver, array $supported): self
    {
        return new self(sprintf(
            'Unknown driver "%s" for "%s". Supported drivers: %s.',
            $driver,
            $key,
            implode(', ', $supported),
        ));
    }

    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Configuration file "%s" does not exist or is not readable.', $path));
    }
}
