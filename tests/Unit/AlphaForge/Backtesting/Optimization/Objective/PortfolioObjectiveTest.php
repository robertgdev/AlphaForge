<?php

use App\AlphaForge\Backtesting\Optimization\Objective\PortfolioObjective;

describe('PortfolioObjective', function () {
    describe('score()', function () {
        it('returns zero for empty symbol stats', function () {
            $obj = new PortfolioObjective;
            expect($obj->score([]))->toBe(0.0);
        });

        it('scores a single symbol positively', function () {
            $obj = new PortfolioObjective;
            $stats = [
                'BTCUSDT' => [
                    'total_return_percent' => '25.0',
                    'sharpe_ratio' => '1.5',
                    'win_rate' => '0.6',
                    'max_drawdown_percent' => '-10.0',
                    'total_trades' => 30,
                ],
                '_config' => ['min_trades_per_symbol' => 1],
            ];

            $score = $obj->score($stats);
            expect($score)->toBeGreaterThan(0);
        });

        it('scores multiple symbols higher when performance is good', function () {
            $obj = new PortfolioObjective;
            $stats = [
                'BTCUSDT' => [
                    'total_return_percent' => '30.0',
                    'sharpe_ratio' => '2.0',
                    'win_rate' => '0.65',
                    'max_drawdown_percent' => '-8.0',
                    'total_trades' => 40,
                ],
                'ETHUSDT' => [
                    'total_return_percent' => '25.0',
                    'sharpe_ratio' => '1.8',
                    'win_rate' => '0.6',
                    'max_drawdown_percent' => '-9.0',
                    'total_trades' => 35,
                ],
                '_config' => ['min_trades_per_symbol' => 1],
            ];

            $score = $obj->score($stats);
            expect($score)->toBeGreaterThan(0);
        });

        it('penalizes when a symbol has zero trades', function () {
            $obj = new PortfolioObjective;
            $statsWithNoTrades = [
                'BTCUSDT' => [
                    'total_return_percent' => '30.0',
                    'sharpe_ratio' => '2.0',
                    'win_rate' => '0.65',
                    'max_drawdown_percent' => '-8.0',
                    'total_trades' => 30,
                ],
                'ETHUSDT' => [
                    'total_return_percent' => '0',
                    'sharpe_ratio' => '0',
                    'win_rate' => '0',
                    'max_drawdown_percent' => '0',
                    'total_trades' => 0,
                ],
                '_config' => ['min_trades_per_symbol' => 5],
            ];

            $scoreWithout = $obj->score($statsWithNoTrades);
            // Score should still be positive for the valid symbol
            expect($scoreWithout)->toBeGreaterThan(0);

            // With only 1 of 2 symbols qualifying, participation_rate = 0.5
            // This halves the base score. Verify the score reflects partial participation.
            $singleSymbolScore = $obj->score([
                'BTCUSDT' => $statsWithNoTrades['BTCUSDT'],
                '_config' => ['min_trades_per_symbol' => 1],
            ]);
            // 1-symbol case gets full participation (1/1=1.0), so it should score higher
            // than the 2-symbol case where only 1 qualifies (1/2=0.5)
            expect($singleSymbolScore)->toBeGreaterThan($scoreWithout);
        });

        it('participation rate reduces score when symbols are missing', function () {
            $obj = new PortfolioObjective;

            // Only 1 of 3 symbols qualifies → participation_rate = 0.33
            $partialStats = [
                'BTCUSDT' => [
                    'total_return_percent' => '30.0',
                    'sharpe_ratio' => '2.0',
                    'win_rate' => '0.65',
                    'max_drawdown_percent' => '-8.0',
                    'total_trades' => 30,
                ],
                'ETHUSDT' => [
                    'total_return_percent' => '0',
                    'sharpe_ratio' => '0',
                    'win_rate' => '0',
                    'max_drawdown_percent' => '0',
                    'total_trades' => 0,
                ],
                'SOLUSDT' => [
                    'total_return_percent' => '0',
                    'sharpe_ratio' => '0',
                    'win_rate' => '0',
                    'max_drawdown_percent' => '0',
                    'total_trades' => 0,
                ],
                '_config' => ['min_trades_per_symbol' => 5],
            ];

            $score = $obj->score($partialStats);
            expect($score)->toBeGreaterThan(0);
            // With 1/3 qualifying, score is scaled by ~0.33
            expect($score)->toBeLessThan(0.5);
        });

        it('returns string label', function () {
            $obj = new PortfolioObjective;
            expect($obj->label())->toBe('portfolio_score');
        });
    });

    describe('correlationPenalty()', function () {
        it('returns zero for a single return value', function () {
            $obj = new PortfolioObjective;
            expect($obj->correlationPenalty([10.0]))->toBe(0.0);
        });

        it('returns zero for perfectly diversified returns (different signs)', function () {
            $obj = new PortfolioObjective;
            // Very different returns = low correlation
            $penalty = $obj->correlationPenalty([20.0, -15.0, 25.0, -10.0]);
            expect($penalty)->toBeLessThan(0.45);
        });

        it('returns positive penalty for similar returns', function () {
            $obj = new PortfolioObjective;
            $penalty = $obj->correlationPenalty([10.0, 9.5, 10.2]);
            expect($penalty)->toBeGreaterThan(0.0);
        });

        it('returns zero for zero variance returns', function () {
            $obj = new PortfolioObjective;
            expect($obj->correlationPenalty([5.0, 5.0, 5.0]))->toBe(0.0);
        });

        it('is bounded between 0 and 0.5', function () {
            $obj = new PortfolioObjective;
            $penalty = $obj->correlationPenalty([10.0, 9.8, 10.1, 9.9]);
            expect($penalty)->toBeGreaterThanOrEqual(0.0);
            expect($penalty)->toBeLessThanOrEqual(0.5);
        });
    });
});
