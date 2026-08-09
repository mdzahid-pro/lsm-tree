<?php

declare(strict_types=1);

namespace Lsm\Trace;

use Lsm\Contract\TraceListenerInterface;
use Lsm\Model\TraceEvent;
use Lsm\Model\TraceEventType;

/**
 * Buffers events so a test can assert on what the engine did, not only on
 * what it returned.
 */
final class CollectingTraceListener implements TraceListenerInterface
{
    /** @var list<TraceEvent> */
    private array $events = [];

    public function record(TraceEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<TraceEvent>
     */
    public function events(?TraceEventType $type = null): array
    {
        if ($type === null) {
            return $this->events;
        }

        return array_values(array_filter(
            $this->events,
            static fn (TraceEvent $event): bool => $event->type === $type,
        ));
    }

    public function countOf(TraceEventType $type): int
    {
        return count($this->events($type));
    }
}
