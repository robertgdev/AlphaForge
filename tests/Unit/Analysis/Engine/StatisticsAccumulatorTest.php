<?php

namespace Tests\Unit\Analysis\Engine;

use App\Analysis\Engine\StatisticsAccumulator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StatisticsAccumulator.
 */
final class StatisticsAccumulatorTest extends TestCase
{
    /**
     * Test empty accumulator.
     */
    public function test_empty_accumulator(): void
    {
        $accumulator = new StatisticsAccumulator;

        $this->assertTrue($accumulator->isEmpty());
        $this->assertEquals(0, $accumulator->getTotalObservations());
        $this->assertEquals(0, $accumulator->getTotalCrosses());
        $this->assertEquals(0, $accumulator->getUniqueBucketCount());
        $this->assertEmpty($accumulator->getResults());
    }

    /**
     * Test recording single observation.
     */
    public function test_record_single_observation(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);

        $this->assertFalse($accumulator->isEmpty());
        $this->assertEquals(1, $accumulator->getTotalObservations());
        $this->assertEquals(1, $accumulator->getTotalCrosses());
        $this->assertEquals(1, $accumulator->getUniqueBucketCount());
    }

    /**
     * Test recording multiple observations in same bucket.
     */
    public function test_record_multiple_observations_same_bucket(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);
        $accumulator->record(0.01, 10, false);
        $accumulator->record(0.01, 10, true);

        $this->assertEquals(3, $accumulator->getTotalObservations());
        $this->assertEquals(2, $accumulator->getTotalCrosses());
        $this->assertEquals(1, $accumulator->getUniqueBucketCount());

        $results = $accumulator->getResults();
        $this->assertCount(1, $results);
        $this->assertEquals(3, $results[0]['total']);
        $this->assertEquals(2, $results[0]['crosses']);
    }

    /**
     * Test recording observations in different buckets.
     */
    public function test_record_observations_different_buckets(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);
        $accumulator->record(0.02, 10, false);
        $accumulator->record(0.01, 5, true);

        $this->assertEquals(3, $accumulator->getTotalObservations());
        $this->assertEquals(2, $accumulator->getTotalCrosses());
        $this->assertEquals(3, $accumulator->getUniqueBucketCount());
    }

    /**
     * Test get statistics.
     */
    public function test_get_statistics(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);
        $accumulator->record(0.01, 10, false);

        $stats = $accumulator->getStatistics();

        $this->assertEquals(2, $stats['total_observations']);
        $this->assertEquals(1, $stats['total_crosses']);
        $this->assertEquals(0.5, $stats['overall_cross_rate']);
        $this->assertEquals(1, $stats['unique_buckets']);
    }

    /**
     * Test heatmap data generation.
     */
    public function test_get_heatmap_data(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);
        $accumulator->record(0.01, 5, false);
        $accumulator->record(0.02, 10, true);

        $heatmap = $accumulator->getHeatmapData();

        $this->assertArrayHasKey(0.01, $heatmap);
        $this->assertArrayHasKey(0.02, $heatmap);
        $this->assertArrayHasKey(10, $heatmap[0.01]);
        $this->assertArrayHasKey(5, $heatmap[0.01]);
        $this->assertEquals(1, $heatmap[0.01][10]['total']);
        $this->assertEquals(1, $heatmap[0.01][10]['crosses']);
    }

    /**
     * Test get specific bucket.
     */
    public function test_get_specific_bucket(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);
        $accumulator->record(0.01, 10, false);

        $data = $accumulator->get(0.01, 10);

        $this->assertNotNull($data);
        $this->assertEquals(2, $data['total']);
        $this->assertEquals(1, $data['crosses']);
    }

    /**
     * Test get non-existent bucket.
     */
    public function test_get_non_existent_bucket(): void
    {
        $accumulator = new StatisticsAccumulator;

        $data = $accumulator->get(0.99, 99);

        $this->assertNull($data);
    }

    /**
     * Test merge accumulators.
     */
    public function test_merge_accumulators(): void
    {
        $accumulator1 = new StatisticsAccumulator;
        $accumulator2 = new StatisticsAccumulator;

        $accumulator1->record(0.01, 10, true);
        $accumulator1->record(0.01, 10, false);

        $accumulator2->record(0.01, 10, true);
        $accumulator2->record(0.02, 5, false);

        $accumulator1->merge($accumulator2);

        $this->assertEquals(4, $accumulator1->getTotalObservations());
        $this->assertEquals(2, $accumulator1->getTotalCrosses());
        $this->assertEquals(2, $accumulator1->getUniqueBucketCount());

        // Check merged bucket
        $data = $accumulator1->get(0.01, 10);
        $this->assertEquals(3, $data['total']);
        $this->assertEquals(2, $data['crosses']);
    }

    /**
     * Test clear accumulator.
     */
    public function test_clear_accumulator(): void
    {
        $accumulator = new StatisticsAccumulator;

        $accumulator->record(0.01, 10, true);
        $accumulator->record(0.02, 5, false);

        $this->assertFalse($accumulator->isEmpty());

        $accumulator->clear();

        $this->assertTrue($accumulator->isEmpty());
        $this->assertEquals(0, $accumulator->getTotalObservations());
        $this->assertEquals(0, $accumulator->getTotalCrosses());
    }

    /**
     * Test precision of bucket keys.
     */
    public function test_bucket_key_precision(): void
    {
        $accumulator = new StatisticsAccumulator;

        // These should all be the same bucket
        $accumulator->record(0.001, 10, true);
        $accumulator->record(0.0010000001, 10, false);
        $accumulator->record(0.0009999999, 10, true);

        $this->assertEquals(3, $accumulator->getTotalObservations());
        $this->assertEquals(1, $accumulator->getUniqueBucketCount());
    }
}
