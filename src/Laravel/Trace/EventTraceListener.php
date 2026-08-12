<?php

declare(strict_types=1);

namespace Lsm\Laravel\Trace;

use Illuminate\Contracts\Events\Dispatcher;
use Lsm\Contract\TraceListenerInterface;
use Lsm\Laravel\Events\CompactionCompleted;
use Lsm\Laravel\Events\MemTableFlushed;
use Lsm\Model\TraceEvent;
use Lsm\Model\TraceEventType;

/**
 * Bridges the engine's trace stream onto Laravel's event dispatcher.
 *
 * Only the two structural events are published. Per-key reads and writes stay
 * inside the engine on purpose: dispatching an event per operation would make
 * the listener the slowest part of the write path.
 */
final readonly class EventTraceListener implements TraceListenerInterface
{
    public function __construct(
        private Dispatcher $events,
        private string $store,
    ) {
    }

    public function record(TraceEvent $event): void
    {
        match ($event->type) {
            TraceEventType::Flush => $this->events->dispatch(new MemTableFlushed(
                $this->store,
                (string) ($event->context['segment'] ?? ''),
                (int) ($event->context['level'] ?? 0),
                (int) ($event->context['entries'] ?? 0),
            )),
            TraceEventType::Compaction => $this->events->dispatch(new CompactionCompleted(
                $this->store,
                array_values(array_map(strval(...), (array) ($event->context['inputs'] ?? []))),
                isset($event->context['segment']) ? (string) $event->context['segment'] : null,
                (int) ($event->context['level'] ?? 0),
                (bool) ($event->context['dropped_tombstones'] ?? false),
            )),
            default => null,
        };
    }
}
