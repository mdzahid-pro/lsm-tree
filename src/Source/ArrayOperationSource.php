<?php

declare(strict_types=1);

namespace Lsm\Source;

use Lsm\Contract\OperationSourceInterface;
use Lsm\Model\Operation;

/**
 * The in-memory fake. Tests build a workload in three lines instead of
 * shipping fixture files, and nothing else in the system has to know.
 */
final readonly class ArrayOperationSource implements OperationSourceInterface
{
    /**
     * @param list<Operation> $operations
     */
    public function __construct(
        private array $operations,
        private string $label = 'in-memory workload',
    ) {
    }

    public function operations(): iterable
    {
        return $this->operations;
    }

    public function describe(): string
    {
        return $this->label;
    }
}
