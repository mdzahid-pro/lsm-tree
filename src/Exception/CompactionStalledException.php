<?php

declare(strict_types=1);

namespace Lsm\Exception;

use RuntimeException;

final class CompactionStalledException extends RuntimeException implements LsmExceptionInterface
{
    public static function afterPasses(int $passes, string $policy): self
    {
        return new self(sprintf(
            'Compaction did not settle after %d passes. The "%s" policy is still asking for work, '
            . 'which means each pass is leaving the tree in a shape that satisfies it again. '
            . 'A policy must eventually stop returning a plan.',
            $passes,
            $policy,
        ));
    }
}
