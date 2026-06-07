<?php

use App\AlphaForge\Backtesting\Service\SeriesMetricService;

describe('SeriesMetricService — trade statistics', function () {
    beforeEach(function () {
        $this->service = new SeriesMetricService;
    });

    describe('sharpeRatioFromReturns', function () {
        it('returns zero for empty returns', function () {
            expect($this->service->sharpeRatioFromReturns([]))->toBe(0.0);
        });

        it('returns zero for single return', function () {
            expect($this->service->sharpeRatioFromReturns([0.05]))->toBe(0.0);
        });

        it('returns zero when all returns are identical', function () {
            expect($this->service->sharpeRatioFromReturns([0.05, 0.05, 0.05]))->toBe(0.0);
        });

        it('calculates positive sharpe for all-positive returns', function () {
            $result = $this->service->sharpeRatioFromReturns([0.05, 0.03, 0.04, 0.06, 0.02]);

            expect($result)->toBeGreaterThan(2.0)
                ->and($result)->toBeLessThan(6.0);
        });

        it('calculates lower sharpe with mixed returns', function () {
            $allPositive = $this->service->sharpeRatioFromReturns([0.05, 0.04, 0.06, 0.03, 0.05]);
            $mixed = $this->service->sharpeRatioFromReturns([0.05, 0.04, -0.02, 0.03, 0.05]);

            expect($mixed)->toBeLessThan($allPositive);
        });

        it('returns negative sharpe for negative returns', function () {
            $result = $this->service->sharpeRatioFromReturns([-0.01, -0.02, -0.005, -0.03]);

            expect($result)->toBeLessThan(0.0);
        });
    });

    describe('sortinoRatioFromReturns', function () {
        it('returns zero for empty returns', function () {
            expect($this->service->sortinoRatioFromReturns([]))->toBe(0.0);
        });

        it('returns zero when no downside returns exist', function () {
            $result = $this->service->sortinoRatioFromReturns([0.05, 0.03, 0.04]);

            expect($result)->toBe(0.0);
        });

        it('returns positive sortino when downside is small relative to positive returns', function () {
            $result = $this->service->sortinoRatioFromReturns([0.05, 0.04, -0.005, 0.06, -0.003]);

            expect($result)->toBeGreaterThan(0.0);
        });

        it('returns negative sortino for all-negative returns', function () {
            $result = $this->service->sortinoRatioFromReturns([-0.01, -0.02, -0.005]);

            expect($result)->toBeLessThan(0.0);
        });

        it('sortino exceeds sharpe when occasional small losses mixed with larger gains', function () {
            $returns = [0.06, -0.005, 0.08, -0.003, 0.05, -0.002];
            $sharpe = $this->service->sharpeRatioFromReturns($returns);
            $sortino = $this->service->sortinoRatioFromReturns($returns);

            expect($sortino)->toBeGreaterThan($sharpe);
        });
    });

    describe('maxDrawdownFromReturns', function () {
        it('returns zero for empty returns', function () {
            expect($this->service->maxDrawdownFromReturns([]))->toBe(0.0);
        });

        it('returns zero when all returns are positive', function () {
            expect($this->service->maxDrawdownFromReturns([0.01, 0.02, 0.03]))->toBe(0.0);
        });

        it('calculates drawdown from sequence with losses', function () {
            $result = $this->service->maxDrawdownFromReturns([0.05, -0.02, 0.03, -0.04]);

            expect($result)->toBeGreaterThan(0.0);
        });

        it('calculates drawdown for consecutive losses', function () {
            $result = $this->service->maxDrawdownFromReturns([0.05, -0.10, -0.05, 0.02]);

            expect($result)->toBeGreaterThan(0.05);
        });
    });

    describe('performanceStabilityFromTrades', function () {
        it('returns zero for empty trades', function () {
            expect($this->service->performanceStabilityFromTrades([]))->toBe(0.0);
        });

        it('returns 1.0 when all days are profitable', function () {
            $trades = [
                ['timestamp' => 1700000000, 'pnl' => 0.05],
                ['timestamp' => 1700086400, 'pnl' => 0.03],
            ];

            expect($this->service->performanceStabilityFromTrades($trades))->toBe(1.0);
        });

        it('returns 0.0 when all days are negative', function () {
            $trades = [
                ['timestamp' => 1700000000, 'pnl' => -0.01],
                ['timestamp' => 1700086400, 'pnl' => -0.02],
            ];

            expect($this->service->performanceStabilityFromTrades($trades))->toBe(0.0);
        });

        it('aggregates multiple trades on the same day', function () {
            $trades = [
                ['timestamp' => 1700000000, 'pnl' => 0.05],
                ['timestamp' => 1700000000, 'pnl' => -0.02],
                ['timestamp' => 1700086400, 'pnl' => -0.01],
            ];

            expect($this->service->performanceStabilityFromTrades($trades))->toBe(0.5);
        });
    });

    describe('tradeWinLossStats', function () {
        it('returns zeros for empty trades', function () {
            $stats = $this->service->tradeWinLossStats([]);

            expect($stats)->toBe([
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'win_rate' => 0.0,
                'expected_value' => 0.0,
            ]);
        });

        it('counts wins and losses correctly', function () {
            $stats = $this->service->tradeWinLossStats([0.05, -0.01, 0.03, -0.02, 0.04]);

            expect($stats['total_trades'])->toBe(5)
                ->and($stats['winning_trades'])->toBe(3)
                ->and($stats['losing_trades'])->toBe(2)
                ->and($stats['win_rate'])->toBe(0.6);
        });

        it('calculates expected value as mean of returns', function () {
            $stats = $this->service->tradeWinLossStats([0.10, 0.05, -0.03]);

            expect($stats['expected_value'])->toEqualWithDelta(0.04, 0.0001);
        });

        it('handles all-winning trades', function () {
            $stats = $this->service->tradeWinLossStats([0.01, 0.02, 0.03]);

            expect($stats['winning_trades'])->toBe(3)
                ->and($stats['losing_trades'])->toBe(0)
                ->and($stats['win_rate'])->toBe(1.0);
        });

        it('handles all-losing trades', function () {
            $stats = $this->service->tradeWinLossStats([-0.01, -0.02, -0.03]);

            expect($stats['winning_trades'])->toBe(0)
                ->and($stats['losing_trades'])->toBe(3)
                ->and($stats['win_rate'])->toBe(0.0);
        });

        it('treats zero-pnl trades as neither win nor loss', function () {
            $stats = $this->service->tradeWinLossStats([0.05, 0.0, -0.01]);

            expect($stats['winning_trades'])->toBe(1)
                ->and($stats['losing_trades'])->toBe(1)
                ->and($stats['total_trades'])->toBe(3);
        });
    });
});
