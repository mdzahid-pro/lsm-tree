<?php

declare(strict_types=1);

namespace Lsm\Model;

enum TraceEventType: string
{
    case Write = 'write';
    case Flush = 'flush';
    case Compaction = 'compaction';
    case ReadHit = 'read.hit';
    case ReadMiss = 'read.miss';
    case FilterSkip = 'read.filter-skip';
    case FilterFalsePositive = 'read.false-positive';
}
