<?php

namespace Tests\Unit\Analysis\Engine;

use App\Analysis\Engine\VolatilityCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VolatilityCalculator.
 */
final class VolatilityCalculatorTest extends TestCase
{
    private VolatilityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new VolatilityCalculator;
    }

    /**
     * Test True Range calculation for first record.
     */
    public function test_true_range_first_record(): void
    {
        $record = [
            'open' => 100.0,
            'high' => 102.0,
            'low' => 98.0,
            'close' => 101.0,
            'volume' => 1.0,
        ];

        $volatilities = $this->calculator->calculateRollingVolatility([$record], 1);

        $this->assertCount(1, $volatilities);
        // True Range for first record = high - low = 4
        // Normalized by close = 4/101 ≈ 0.0396
        $this->assertEqualsWithDelta(0.0396, $volatilities[0], 0.001);
    }

    /**
     * Test True Range calculation with previous close.
     */
    public function test_true_range_with_previous_close(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 102.0, 'low' => 98.0, 'close' => 101.0, 'volume' => 1.0],
            ['open' => 101.0, 'high' => 105.0, 'low' => 100.0, 'close' => 104.0, 'volume' => 1.0],
        ];

        $volatilities = $this->calculator->calculateRollingVolatility($records, 2);

        $this->assertCount(2, $volatilities);

        // Second record True Range = max(105-100, |105-101|, |100-101|) = max(5, 4, 1) = 5
        // ATR = (4 + 5) / 2 = 4.5
        // Normalized by close = 4.5/104 ≈ 0.0433
        $this->assertEqualsWithDelta(0.0433, $volatilities[1], 0.001);
    }

    /**
     * Test rolling volatility calculation.
     */
    public function test_rolling_volatility(): void
    {
        $records = [];
        for ($i = 0; $i < 10; $i++) {
            $records[] = [
                'open' => 100.0 + $i,
                'high' => 102.0 + $i,
                'low' => 98.0 + $i,
                'close' => 100.0 + $i,
                'volume' => 1.0,
            ];
        }

        $volatilities = $this->calculator->calculateRollingVolatility($records, 5);

        $this->assertCount(10, $volatilities);

        // First volatility should be based on single record
        $this->assertEqualsWithDelta(0.04, $volatilities[0], 0.001);

        // Later volatilities should be based on rolling average
        foreach ($volatilities as $vol) {
            $this->assertGreaterThan(0, $vol);
            $this->assertLessThan(1, $vol);
        }
    }

    /**
     * Test block volatility calculation.
     */
    public function test_block_volatility(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 102.0, 'low' => 98.0, 'close' => 101.0, 'volume' => 1.0],
            ['open' => 101.0, 'high' => 103.0, 'low' => 99.0, 'close' => 102.0, 'volume' => 1.0],
            ['open' => 102.0, 'high' => 104.0, 'low' => 100.0, 'close' => 103.0, 'volume' => 1.0],
        ];

        $volatility = $this->calculator->calculateBlockVolatility($records);

        $this->assertGreaterThan(0, $volatility);
        $this->assertLessThan(1, $volatility);
    }

    /**
     * Test block volatility with single record.
     */
    public function test_block_volatility_single_record(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 102.0, 'low' => 98.0, 'close' => 101.0, 'volume' => 1.0],
        ];

        $volatility = $this->calculator->calculateBlockVolatility($records);

        // Single record: TR = high - low = 4, normalized by close = 4/101
        $this->assertEqualsWithDelta(0.0396, $volatility, 0.001);
    }

    /**
     * Test block volatility with empty records.
     */
    public function test_block_volatility_empty_records(): void
    {
        $volatility = $this->calculator->calculateBlockVolatility([]);

        $this->assertEquals(0.0, $volatility);
    }

    /**
     * Test standard deviation volatility calculation.
     */
    public function test_std_dev_volatility(): void
    {
        $records = [];
        for ($i = 0; $i < 20; $i++) {
            $price = 100.0 + sin($i * 0.5) * 2; // Oscillating price
            $records[] = [
                'open' => $price,
                'high' => $price + 1,
                'low' => $price - 1,
                'close' => $price,
                'volume' => 1.0,
            ];
        }

        $volatilities = $this->calculator->calculateStdDevVolatility($records, 10);

        $this->assertCount(20, $volatilities);

        // First record has no return, so volatility should be 0
        $this->assertEquals(0.0, $volatilities[0]);

        // Later records should have positive volatility
        for ($i = 2; $i < 20; $i++) {
            $this->assertGreaterThan(0, $volatilities[$i]);
        }
    }

    /**
     * Test volatility for normalization with minimum threshold.
     */
    public function test_volatility_for_normalization(): void
    {
        $volatilities = [0.01, 0.02, 0.0, 0.001];

        $result0 = $this->calculator->getVolatilityForNormalization($volatilities, 0);
        $this->assertEquals(0.01, $result0);

        $result2 = $this->calculator->getVolatilityForNormalization($volatilities, 2);
        $this->assertEquals(0.0001, $result2); // Minimum threshold

        $result3 = $this->calculator->getVolatilityForNormalization($volatilities, 3);
        $this->assertEquals(0.001, $result3);
    }

    /**
     * Test volatility for normalization with missing index.
     */
    public function test_volatility_for_normalization_missing_index(): void
    {
        $volatilities = [0.01, 0.02];

        $result = $this->calculator->getVolatilityForNormalization($volatilities, 99);

        // Missing index should return minimum threshold
        $this->assertEquals(0.0001, $result);
    }
}
