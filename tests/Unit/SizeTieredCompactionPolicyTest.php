<?php

declare(strict_types=1);

namespace Lsm\Tests\Unit;

use Lsm\Compaction\SizeTieredCompactionPolicy;
use Lsm\Exception\InvalidConfigurationException;
use Lsm\Filter\NullKeyFilterFactory;
use Lsm\Model\Entry;
use Lsm\Segment\SegmentFactory;
use Lsm\Sequence\SequentialSegmentIdGenerator;
use Lsm\Storage\InMemorySegmentStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SizeTieredCompactionPolicyTest extends TestCase
{
    #[Test]
    public function a_level_under_the_threshold_is_left_alone(): void
    {
        $store = $this->store(levels: [0 => 2]);

        self::assertNull((new SizeTieredCompactionPolicy(3, 2))->plan($store));
    }

    #[Test]
    public function the_shallowest_overfull_level_is_chosen_first(): void
    {
        $store = $this->store(levels: [0 => 3, 1 => 3]);

        $plan = (new SizeTieredCompactionPolicy(3, 2))->plan($store);

        self::assertNotNull($plan);
        self::assertCount(3, $plan->inputs);
        self::assertSame(1, $plan->targetLevel);
    }

    #[Test]
    public function the_bottom_level_merges_in_place(): void
    {
        $plan = (new SizeTieredCompactionPolicy(2, 1))->plan($this->store(levels: [1 => 2]));

        self::assertNotNull($plan);
        self::assertSame(1, $plan->targetLevel);
    }

    /**
     * The bug this guards against: dropping a tombstone while an unmerged run
     * at the target level still holds an older copy of the same key brings the
     * deleted value back to life.
     */
    #[Test]
    public function tombstones_are_kept_unless_the_bottom_level_merges_into_itself(): void
    {
        $policy = new SizeTieredCompactionPolicy(2, 2);

        $shallow = $policy->plan($this->store(levels: [0 => 2]));
        self::assertNotNull($shallow);
        self::assertFalse($shallow->dropTombstones, 'L0 merging into L1 must keep tombstones.');

        $middle = $policy->plan($this->store(levels: [1 => 2]));
        self::assertNotNull($middle);
        self::assertFalse($middle->dropTombstones, 'L1 merging into L2 must keep tombstones.');

        $bottom = $policy->plan($this->store(levels: [2 => 2]));
        self::assertNotNull($bottom);
        self::assertTrue($bottom->dropTombstones, 'The bottom level merging in place may drop them.');
    }

    #[Test]
    public function nonsensical_configuration_is_rejected_at_construction(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new SizeTieredCompactionPolicy(1, 2);
    }

    /**
     * @param array<int, int> $levels level => number of runs
     */
    private function store(array $levels): InMemorySegmentStore
    {
        $store = new InMemorySegmentStore(
            new SegmentFactory(new SequentialSegmentIdGenerator, new NullKeyFilterFactory),
        );

        foreach ($levels as $level => $runs) {
            for ($i = 0; $i < $runs; $i++) {
                $store->write([new Entry('key' . $level . $i, 'v', 1)], $level);
            }
        }

        return $store;
    }
}
