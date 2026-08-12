<?php

declare(strict_types=1);

namespace Lsm\Exception;

use RuntimeException;

final class SegmentNotFoundException extends RuntimeException implements LsmExceptionInterface
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Segment "%s" is not tracked by this segment store.', $id));
    }
}
