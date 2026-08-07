<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Model\TraceEvent;

/**
 * Observes the engine without changing it.
 *
 * One method keeps the interface honest: implementations that only care about
 * compactions filter on the event type instead of being forced to stub out a
 * dozen empty methods.
 */
interface TraceListenerInterface
{
    public function record(TraceEvent $event): void;
}
