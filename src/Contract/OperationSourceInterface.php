<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Model\Operation;

/**
 * Where the workload comes from.
 *
 * This is the seam the tests exploit: the CLI reads JSON Lines or CSV from
 * disk, the test suite hands over an in-memory array, and the engine cannot
 * tell the difference.
 */
interface OperationSourceInterface
{
    /**
     * @return iterable<int, Operation>
     */
    public function operations(): iterable;

    /**
     * A human-readable label for logs and error messages.
     */
    public function describe(): string;
}
