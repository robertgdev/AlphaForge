<?php

use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;

describe('BacktestResultFormatter', function () {
    beforeEach(function () {
        $this->formatter = new BacktestResultFormatter;
    });

    describe('formatCapitalSummary', function () {
        it('formats profitable result', function () {
            $result = $this->formatter->formatCapitalSummary([
                'final_capital' => 11000,
                'initial_capital' => 10000,
            ]);

            expect($result['final_capital'])->toBe('11,000.00')
                ->and($result['initial_capital'])->toBe('10,000.00')
                ->and($result['pnl']['absolute'])->toBe('+1,000.00')
                ->and($result['pnl']['percent'])->toBe('+10.00')
                ->and($result['pnl']['color'])->toBe('<fg=green>');
        });

        it('formats losing result', function () {
            $result = $this->formatter->formatCapitalSummary([
                'final_capital' => 9000,
                'initial_capital' => 10000,
            ]);

            expect($result['pnl']['absolute'])->toBe('-1,000.00')
                ->and($result['pnl']['percent'])->toBe('-10.00')
                ->and($result['pnl']['color'])->toBe('<fg=red>');
        });

        it('formats break-even result', function () {
            $result = $this->formatter->formatCapitalSummary([
                'final_capital' => 10000,
                'initial_capital' => 10000,
            ]);

            expect($result['pnl']['absolute'])->toBe('+0.00')
                ->and($result['pnl']['percent'])->toBe('+0.00')
                ->and($result['pnl']['color'])->toBe('<fg=green>');
        });

        it('includes execution timeframe when provided', function () {
            $result = $this->formatter->formatCapitalSummary([
                'final_capital' => 11000,
                'initial_capital' => 10000,
                'execution_timeframe' => '5m',
            ]);

            expect($result['execution_timeframe'])->toBe('5m');
        });

        it('returns null execution timeframe when not provided', function () {
            $result = $this->formatter->formatCapitalSummary([
                'final_capital' => 11000,
                'initial_capital' => 10000,
            ]);

            expect($result['execution_timeframe'])->toBeNull();
        });

        it('handles zero initial capital', function () {
            $result = $this->formatter->formatCapitalSummary([
                'final_capital' => 1000,
                'initial_capital' => 0,
            ]);

            expect($result['pnl']['percent'])->toBe('+0.00');
        });
    });

    describe('formatStatistics', function () {
        it('formats all known statistics keys', function () {
            $stats = [
                'total_trades' => 50,
                'winning_trades' => 30,
                'losing_trades' => 20,
                'win_rate' => 0.6,
                'profit_factor' => 1.5,
                'max_drawdown_percent' => 0.15,
                'sharpe_ratio' => 1.23,
                'total_return_percent' => 0.45,
            ];

            $result = $this->formatter->formatStatistics($stats);

            expect($result)->toHaveKey('Total Trades')
                ->and($result)->toHaveKey('Winning Trades')
                ->and($result)->toHaveKey('Losing Trades')
                ->and($result)->toHaveKey('Win Rate')
                ->and($result)->toHaveKey('Profit Factor')
                ->and($result)->toHaveKey('Max Drawdown')
                ->and($result)->toHaveKey('Sharpe Ratio')
                ->and($result)->toHaveKey('Total Return');
        });

        it('formats win rate as percentage', function () {
            $stats = ['win_rate' => 0.65];

            $result = $this->formatter->formatStatistics($stats);

            expect($result['Win Rate'])->toBe('65.00%');
        });

        it('formats max drawdown as percentage', function () {
            $stats = ['max_drawdown_percent' => 0.1234];

            $result = $this->formatter->formatStatistics($stats);

            expect($result['Max Drawdown'])->toBe('12.34%');
        });

        it('formats total return as percentage', function () {
            $stats = ['total_return_percent' => 50];

            $result = $this->formatter->formatStatistics($stats);

            expect($result['Total Return'])->toBe('50.00%');
        });

        it('returns empty array for empty stats', function () {
            $result = $this->formatter->formatStatistics([]);

            expect($result)->toBeEmpty();
        });

        it('skips unknown keys', function () {
            $result = $this->formatter->formatStatistics([
                'unknown_metric' => 42,
            ]);

            expect($result)->toBeEmpty();
        });
    });

    describe('formatPositions', function () {
        it('formats array positions', function () {
            $positions = [
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 50000,
                    'exitPrice' => 55000,
                    'realizedPnl' => 1000,
                ],
            ];

            $result = $this->formatter->formatPositions($positions);

            expect($result)->toHaveCount(1)
                ->and($result[0][0])->toBe('BTC/USDT')
                ->and($result[0][1])->toBe('long')
                ->and($result[0][2])->toBe('50,000.00')
                ->and($result[0][3])->toBe('55,000.00')
                ->and($result[0][4])->toBe('1,000.00');
        });

        it('formats object positions', function () {
            $position = new stdClass;
            $position->symbol = 'ETH/USDT';
            $position->direction = 'short';
            $position->entryPrice = 3000;
            $position->exitPrice = 2800;
            $position->realizedPnl = -200;

            $result = $this->formatter->formatPositions([$position]);

            expect($result)->toHaveCount(1)
                ->and($result[0][0])->toBe('ETH/USDT')
                ->and($result[0][1])->toBe('short')
                ->and($result[0][4])->toBe('-200.00');
        });

        it('handles missing fields with defaults', function () {
            $positions = [
                ['symbol' => 'BTC/USDT'],
            ];

            $result = $this->formatter->formatPositions($positions);

            expect($result[0][0])->toBe('BTC/USDT')
                ->and($result[0][1])->toBe('unknown')
                ->and($result[0][2])->toBe('0.00')
                ->and($result[0][3])->toBe('0.00')
                ->and($result[0][4])->toBe('0.00');
        });

        it('handles missing symbol with question mark', function () {
            $positions = [
                ['direction' => 'long'],
            ];

            $result = $this->formatter->formatPositions($positions);

            expect($result[0][0])->toBe('?');
        });

        it('returns empty array for no positions', function () {
            $result = $this->formatter->formatPositions([]);

            expect($result)->toBeEmpty();
        });
    });
});
