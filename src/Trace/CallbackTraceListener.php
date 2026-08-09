<?php

declare(strict_types=1);

namespace Lsm\Trace;

use Closure;
use Lsm\Contract\TraceListenerInterface;
use Lsm\Model\TraceEvent;

/**
 * Hands each event to a closure — the adapter a console command uses to print
 * the engine's activity as it happens.
 */
final readonly class CallbackTraceListener implements TraceListenerInterface
{
    /** @var Closure(TraceEvent): void */
    private Closure $callback;

    /**
     * @param callable(TraceEvent): void $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function record(TraceEvent $event): void
    {
        ($this->callback)($event);
    }
}
