<?php

declare(strict_types=1);

namespace Lsm\Contract;

/**
 * Makes the filter that guards a run.
 *
 * Bloom is the usual answer, but a cuckoo filter, a ribbon filter or nothing
 * at all all satisfy this contract, and the engine cannot tell which it got.
 */
interface KeyFilterFactoryInterface
{
    /**
     * @param int $estimatedKeys an upper bound on the number of keys, used to
     *                           size the filter before the first key arrives
     */
    public function builder(int $estimatedKeys): KeyFilterBuilderInterface;
}
