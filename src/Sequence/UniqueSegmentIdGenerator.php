<?php

declare(strict_types=1);

namespace Lsm\Sequence;

use Lsm\Contract\SegmentIdGeneratorInterface;

/**
 * Names runs so that two processes writing to one store cannot collide.
 *
 * The counter-based generator restarts at one on every boot, which is fine
 * while the hierarchy lives in a single process and catastrophic the moment it
 * is shared: two workers would both name their first run "S1" and each would
 * happily delete the other's rows during compaction.
 *
 * Ids are time-ordered so that sorting them lexicographically also sorts them
 * by age, which makes a segments table readable by a human.
 */
final class UniqueSegmentIdGenerator implements SegmentIdGeneratorInterface
{
    public function __construct(private readonly string $prefix = 'seg')
    {
    }

    public function next(): string
    {
        return sprintf(
            '%s_%s%s',
            $this->prefix,
            str_pad(base_convert((string) (int) (microtime(true) * 1000), 10, 36), 9, '0', STR_PAD_LEFT),
            bin2hex(random_bytes(5)),
        );
    }
}
