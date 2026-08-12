<?php

declare(strict_types=1);

namespace Lsm\Trace;

use Lsm\Contract\TraceListenerInterface;
use Lsm\Model\TraceEvent;

final class NullTraceListener implements TraceListenerInterface
{
    public function record(TraceEvent $event): void
    {
    }
}
