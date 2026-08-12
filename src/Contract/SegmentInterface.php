<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Model\Entry;

/**
 * An immutable sorted run.
 *
 * Deliberately behavioural rather than a data holder: an implementation may
 * keep every entry in RAM, or hold nothing but metadata and reach for rows
 * only when asked. The engine cannot tell the difference, which is what lets
 * a run of ten million entries be read without loading it.
 */
interface SegmentInterface
{
    public function id(): string;

    public function level(): int;

    public function count(): int;

    public function minKey(): string;

    public function maxKey(): string;

    /**
     * Cheap negative test. False means the key is definitely absent; true
     * means it may be present and a real lookup is required.
     */
    public function mightContain(string $key): bool;

    public function get(string $key): ?Entry;

    /**
     * @return iterable<Entry> ascending by key, streamed where possible
     */
    public function entries(): iterable;
}
