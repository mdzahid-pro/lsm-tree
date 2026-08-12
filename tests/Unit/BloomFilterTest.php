<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Filter\BloomFilter;
use Lsm\Filter\BloomFilterFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BloomFilterTest extends TestCase
{
    /**
     * The one property that must never break. A false negative would make the
     * engine skip a run that holds live data, and the read would silently
     * return nothing.
     */
    #[Test]
    public function every_key_it_was_built_from_is_reported_as_possibly_present(): void
    {
        $keys = array_map(static fn (int $i): string => 'key-' . $i, range(1, 500));

        $builder = (new BloomFilterFactory(10, 7))->builder(count($keys));

        foreach ($keys as $key) {
            $builder->add($key);
        }

        $filter = $builder->build();

        foreach ($keys as $key) {
            self::assertTrue($filter->mightContain($key), sprintf('False negative for %s.', $key));
        }
    }

    #[Test]
    public function absent_keys_are_usually_rejected(): void
    {
        $builder = (new BloomFilterFactory(10, 7))->builder(1000);

        for ($i = 0; $i < 1000; $i++) {
            $builder->add('present-' . $i);
        }

        $filter = $builder->build();
        $falsePositives = 0;

        for ($i = 0; $i < 1000; $i++) {
            if ($filter->mightContain('absent-' . $i)) {
                $falsePositives++;
            }
        }

        // Ten bits per key with seven hashes targets roughly one percent. The
        // bound here is loose on purpose: this is a smoke test for a broken
        // hash, not a statistical assertion.
        self::assertLessThan(100, $falsePositives);
    }

    #[Test]
    public function a_filter_survives_a_round_trip_through_its_packed_form(): void
    {
        $builder = (new BloomFilterFactory(10, 7))->builder(50);

        foreach (range(1, 50) as $i) {
            $builder->add('key-' . $i);
        }

        $original = $builder->build();
        self::assertInstanceOf(BloomFilter::class, $original);

        $restored = new BloomFilter(
            (string) base64_decode(base64_encode($original->bits()), true),
            $original->size(),
            $original->hashCount(),
        );

        foreach (range(1, 50) as $i) {
            self::assertSame(
                $original->mightContain('key-' . $i),
                $restored->mightContain('key-' . $i),
            );
        }
    }

    #[Test]
    public function positions_stay_inside_the_vector(): void
    {
        foreach (BloomFilter::positions('anything', 64, 7) as $position) {
            self::assertGreaterThanOrEqual(0, $position);
            self::assertLessThan(64, $position);
        }
    }
}
