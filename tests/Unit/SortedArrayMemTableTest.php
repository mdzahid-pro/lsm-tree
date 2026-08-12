<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Exception\InvalidConfigurationException;
use Lsm\MemTable\SortedArrayMemTable;
use Lsm\Model\Entry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SortedArrayMemTableTest extends TestCase
{
    #[Test]
    public function entries_come_out_sorted_by_key(): void
    {
        $table = new SortedArrayMemTable(10);
        $table->put(new Entry('m', '1', 1));
        $table->put(new Entry('a', '2', 2));
        $table->put(new Entry('z', '3', 3));

        self::assertSame(['a', 'm', 'z'], array_map(
            static fn (Entry $entry): string => $entry->key,
            $table->entries(),
        ));
    }

    /**
     * Numeric-looking keys are the classic trap: PHP's default sort would put
     * "10" before "9", and the binary search inside a run would then miss.
     */
    #[Test]
    public function numeric_looking_keys_are_sorted_as_strings(): void
    {
        $table = new SortedArrayMemTable(10);

        foreach (['9', '10', '100', '2'] as $key) {
            $table->put(new Entry($key, 'v', 1));
        }

        self::assertSame(['10', '100', '2', '9'], array_map(
            static fn (Entry $entry): string => $entry->key,
            $table->entries(),
        ));
    }

    #[Test]
    public function writing_the_same_key_twice_replaces_it_rather_than_growing(): void
    {
        $table = new SortedArrayMemTable(10);
        $table->put(new Entry('a', 'old', 1));
        $table->put(new Entry('a', 'new', 2));

        self::assertCount(1, $table);
        self::assertSame('new', $table->get('a')?->value);
    }

    #[Test]
    public function it_reports_fullness_at_its_capacity(): void
    {
        $table = new SortedArrayMemTable(2);
        $table->put(new Entry('a', '1', 1));

        self::assertFalse($table->isFull());

        $table->put(new Entry('b', '2', 2));

        self::assertTrue($table->isFull());
        self::assertSame(2, $table->capacity());
    }

    #[Test]
    public function a_capacity_below_one_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new SortedArrayMemTable(0);
    }
}
