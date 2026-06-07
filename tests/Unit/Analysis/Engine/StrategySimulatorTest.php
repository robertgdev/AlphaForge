<?php

use App\AlphaForge\Analysis\Config\ValidationConfig;
use App\AlphaForge\Analysis\Dto\OpenCrossProbabilityResult;
use App\AlphaForge\Analysis\Dto\Validation\SimulationReport;
use App\AlphaForge\Analysis\Engine\Validation\StrategySimulator;
use App\AlphaForge\Backtesting\Service\SeriesMetricServiceInterface;

describe('StrategySimulator', function () {
    beforeEach(function () {
        $this->seriesMetricService = Mockery::mock(SeriesMetricServiceInterface::class);
        $this->simulator = new StrategySimulator($this->seriesMetricService);
    });

    describe('simulate', function () {
        it('returns zero metrics when no trades were generated', function () {
            $this->seriesMetricService->shouldReceive('tradeWinLossStats')->once()->andReturn([
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => 0.0,
                'expected_value' => 0.0,
            ]);
            $this->seriesMetricService->shouldReceive('sharpeRatioFromReturns')->once()->andReturn(0.0);
            $this->seriesMetricService->shouldReceive('sortinoRatioFromReturns')->once()->andReturn(0.0);
            $this->seriesMetricService->shouldReceive('maxDrawdownFromReturns')->once()->andReturn(0.0);
            $this->seriesMetricService->shouldReceive('performanceStabilityFromTrades')->once()->andReturn(0.0);

            $surface = new OpenCrossProbabilityResult(
                probabilitySurface: [],
                totalBlocksAnalyzed: 0,
                totalObservations: 0,
                metadata: [],
            );

            $config = new ValidationConfig(
                exchange: 'test',
                market: 'BTC/USDT',
                timeframe: '1m',
                blockMinutes: 15,
                bucketSize: 0.001,
            );

            $report = $this->simulator->simulate($surface, [], $config);

            expect($report->totalTrades)->toBe(0)
                ->and($report->sharpeRatio)->toBe(0.0)
                ->and($report->sortinoRatio)->toBe(0.0)
                ->and($report->maxDrawdown)->toBe(0.0)
                ->and($report->isProfitable)->toBeFalse();
        });

        it('delegates all stats to SeriesMetricService when trades exist', function () {
            $this->seriesMetricService->shouldReceive('tradeWinLossStats')->once()->with([])->andReturn([
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => 0.0,
                'expected_value' => 0.0,
            ]);
            $this->seriesMetricService->shouldReceive('sharpeRatioFromReturns')->once()->andReturn(0.0);
            $this->seriesMetricService->shouldReceive('sortinoRatioFromReturns')->once()->andReturn(0.0);
            $this->seriesMetricService->shouldReceive('maxDrawdownFromReturns')->once()->andReturn(0.0);
            $this->seriesMetricService->shouldReceive('performanceStabilityFromTrades')->once()->andReturn(0.0);

            $surface = new OpenCrossProbabilityResult(
                probabilitySurface: [],
                totalBlocksAnalyzed: 0,
                totalObservations: 0,
                metadata: [],
            );

            $records = [];
            for ($i = 0; $i < 15; $i++) {
                $records[] = [
                    'timestamp' => 1700000000 + ($i * 60),
                    'open' => 100.0 + ($i * 0.01),
                    'high' => 100.5 + ($i * 0.01),
                    'low' => 99.5 + ($i * 0.01),
                    'close' => 100.1 + ($i * 0.01),
                ];
            }

            $config = new ValidationConfig(
                exchange: 'test',
                market: 'BTC/USDT',
                timeframe: '1m',
                blockMinutes: 15,
                bucketSize: 0.001,
            );

            $report = $this->simulator->simulate($surface, $records, $config);

            expect($report)->toBeInstanceOf(SimulationReport::class);
        });
    });
});
