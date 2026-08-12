<?php

declare(strict_types=1);

namespace Lsm\Laravel\Trace;

use Lsm\Contract\TraceListenerInterface;
use Lsm\Model\TraceEvent;
use Lsm\Model\TraceEventType;
use Psr\Log\LoggerInterface;

/**
 * Writes engine activity to a PSR logger.
 *
 * Off by default. Every write produces an event, so leaving this on at debug
 * level in production will fill a disk. The type filter exists so that an
 * operator can log flushes and compactions only, which is what you actually
 * want on a live system.
 */
final readonly class LogTraceListener implements TraceListenerInterface
{
    /**
     * @param list<TraceEventType> $only an empty list means every type
     */
    public function __construct(
        private LoggerInterface $logger,
        private string $store,
        private string $level = 'debug',
        private array $only = [],
    ) {
    }

    /**
     * Maps configured type names onto enum cases, ignoring anything unknown.
     *
     * Logging every write is almost never what an operator wants, so the
     * config file offers ['flush', 'compaction'] as the sensible default.
     *
     * @param array<int, mixed> $names
     *
     * @return list<TraceEventType>
     */
    public static function types(array $names): array
    {
        $types = [];

        foreach ($names as $name) {
            $type = is_string($name) ? TraceEventType::tryFrom($name) : null;

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public function record(TraceEvent $event): void
    {
        if ($this->only !== [] && !in_array($event->type, $this->only, true)) {
            return;
        }

        $this->logger->log($this->level, sprintf('lsm[%s] %s', $this->store, $event->message), [
            'store' => $this->store,
            'type' => $event->type->value,
        ] + $event->context);
    }
}
