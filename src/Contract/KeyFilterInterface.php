<?php

declare(strict_types=1);

namespace Lsm\Contract;

/**
 * A probabilistic membership test guarding a run.
 *
 * The contract is deliberately one-sided: false means definitely absent and is
 * safe to act on, true means possibly present and must be verified. Any
 * implementation that can return a false negative breaks the engine.
 */
interface KeyFilterInterface
{
    public function mightContain(string $key): bool;
}
