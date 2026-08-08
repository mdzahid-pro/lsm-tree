<?php

declare(strict_types=1);

namespace Lsm\Model;

/**
 * What a workload did, reported back to whoever started it.
 */
final readonly class RunSummary
{
    /**
     * @param array<string, string|null> $reads key => value at read time
     */
    public function __construct(
        public int $puts,
        public int $deletes,
        public int $gets,
        public array $reads,
    ) {}

    public function total(): int
    {
        return $this->puts + $this->deletes + $this->gets;
    }
}
