<?php

declare(strict_types=1);

namespace Lsm\Sequence;

use Lsm\Contract\SequenceGeneratorInterface;

final class InMemorySequenceGenerator implements SequenceGeneratorInterface
{
    public function __construct(private int $value = 0) {}

    public function next(): int
    {
        return ++$this->value;
    }

    public function current(): int
    {
        return $this->value;
    }

    public function advanceTo(int $value): void
    {
        $this->value = max($this->value, $value);
    }
}
