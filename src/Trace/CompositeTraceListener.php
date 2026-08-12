<?php

declare(strict_types=1);

namespace Lsm\Trace;

use Lsm\Contract\TraceListenerInterface;
use Lsm\Model\TraceEvent;

/**
 * Fans one event out to several listeners.
 *
 * Lets an application log events, dispatch them as framework events and
 * collect them in a test without the engine ever holding more than the one
 * listener its constructor asks for.
 */
final readonly class CompositeTraceListener implements TraceListenerInterface
{
    /** @var list<TraceListenerInterface> */
    private array $listeners;

    public function __construct(TraceListenerInterface ...$listeners)
    {
        $this->listeners = array_values($listeners);
    }

    public function record(TraceEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener->record($event);
        }
    }

    public function isEmpty(): bool
    {
        return $this->listeners === [];
    }
}
