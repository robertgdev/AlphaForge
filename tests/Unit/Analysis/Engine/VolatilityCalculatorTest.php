<?php

namespace Tests\Unit\Analysis\Engine;

use App\Analysis\Engine\VolatilityCalculator;
use PHPUnit\Framework\TestCase;

final class VolatilityCalculatorTest extends TestCase
{
    private VolatilityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new VolatilityCalculator;
    }

    public function test_rolling_volatility_returns_zero_for_single_record(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 102.0, 'low' => 98.0, 'close' => 101.0, 'volume' => 1.0],
        ];

        $volatilities = $this->calculator->calculateRollingVolatility($records, 1);

        $this->assertCount(1, $volatilities);
        $this->assertSame(0.0, $volatilities[0]);
    }

    public function test_rolling_volatility_returns_zeros_for_empty_array(): void
    {
        $volatilities = $this->calculator->calculateRollingVolatility([], 5);

        $this->assertCount(0, $volatilities);
    }

    public function test_rolling_volatility_computes_log_return_std_dev(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 102.0, 'low' => 98.0, 'close' => 100.0, 'volume' => 1.0],
            ['open' => 100.0, 'high' => 103.0, 'low' => 99.0, 'close' => 101.0, 'volume' => 1.0],
            ['open' => 101.0, 'high' => 104.0, 'low' => 100.0, 'close' => 102.0, 'volume' => 1.0],
        ];

        $volatilities = $this->calculator->calculateRollingVolatility($records, 3);

        $this->assertCount(3, $volatilities);
        $this->assertSame(0.0, $volatilities[0]);
        $this->assertGreaterThan(0, $volatilities[1]);
        $this->assertGreaterThan(0, $volatilities[2]);
    }

    public function test_rolling_volatility_with_constant_price(): void
    {
        $records = [];
        for ($i = 0; $i < 10; $i++) {
            $records[] = [
                'open' => 100.0, 'high' => 100.0, 'low' => 100.0, 'close' => 100.0, 'volume' => 1.0,
            ];
        }

        $volatilities = $this->calculator->calculateRollingVolatility($records, 5);

        $this->assertCount(10, $volatilities);
        foreach ($volatilities as $vol) {
            $this->assertEqualsWithDelta(0.0, $vol, 0.0001);
        }
    }

    public function test_rolling_volatility_increases_with_larger_swings(): void
    {
        $stableRecords = [];
        $volatileRecords = [];

        for ($i = 0; $i < 20; $i++) {
            $stableRecords[] = [
                'open' => 100.0, 'high' => 100.5, 'low' => 99.5,
                'close' => 100.0 + ($i % 2 === 0 ? 0.01 : -0.01), 'volume' => 1.0,
            ];
            $volatileRecords[] = [
                'open' => 100.0, 'high' => 110.0, 'low' => 90.0,
                'close' => 100.0 + ($i % 2 === 0 ? 5.0 : -5.0), 'volume' => 1.0,
            ];
        }

        $stableVols = $this->calculator->calculateRollingVolatility($stableRecords, 10);
        $volatileVols = $this->calculator->calculateRollingVolatility($volatileRecords, 10);

        $stableAvg = array_sum(array_slice($stableVols, 5)) / 15;
        $volatileAvg = array_sum(array_slice($volatileVols, 5)) / 15;

        $this->assertGreaterThan($stableAvg, $volatileAvg);
    }

    public function test_block_volatility_with_multiple_records(): void
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

    public function test_block_volatility_with_single_record(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 102.0, 'low' => 98.0, 'close' => 101.0, 'volume' => 1.0],
        ];

        $volatility = $this->calculator->calculateBlockVolatility($records);

        $this->assertSame(0.0, $volatility);
    }

    public function test_block_volatility_with_empty_records(): void
    {
        $volatility = $this->calculator->calculateBlockVolatility([]);

        $this->assertSame(0.0, $volatility);
    }

    public function test_block_volatility_with_constant_price(): void
    {
        $records = [
            ['open' => 100.0, 'high' => 100.0, 'low' => 100.0, 'close' => 100.0, 'volume' => 1.0],
            ['open' => 100.0, 'high' => 100.0, 'low' => 100.0, 'close' => 100.0, 'volume' => 1.0],
        ];

        $volatility = $this->calculator->calculateBlockVolatility($records);

        $this->assertEqualsWithDelta(0.0, $volatility, 0.0001);
    }

    public function test_volatility_for_normalization_returns_value_when_present(): void
    {
        $volatilities = [0.01, 0.02, 0.0, 0.001];

        $result0 = $this->calculator->getVolatilityForNormalization($volatilities, 0);
        $this->assertEqualsWithDelta(0.01, $result0, 0.0001);

        $result3 = $this->calculator->getVolatilityForNormalization($volatilities, 3);
        $this->assertEqualsWithDelta(0.001, $result3, 0.0001);
    }

    public function test_volatility_for_normalization_applies_minimum_floor(): void
    {
        $volatilities = [0.01, 0.02, 0.0, 0.001];

        $result2 = $this->calculator->getVolatilityForNormalization($volatilities, 2);
        $this->assertEqualsWithDelta(0.001, $result2, 0.0001);
    }

    public function test_volatility_for_normalization_missing_index_returns_minimum(): void
    {
        $volatilities = [0.01, 0.02];

        $result = $this->calculator->getVolatilityForNormalization($volatilities, 99);

        $this->assertEqualsWithDelta(0.001, $result, 0.0001);
    }
}
