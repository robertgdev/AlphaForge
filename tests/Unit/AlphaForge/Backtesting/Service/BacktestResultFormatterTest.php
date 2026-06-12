<?php

use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use Carbon\Carbon;

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

        it('uses 2 decimal places for small positive returns', function () {
            $stats = ['total_return_percent' => 0.1415];

            $result = $this->formatter->formatStatistics($stats);

            expect($result['Total Return'])->toBe('0.14%');
        });

        it('uses 2 decimal places for small negative returns', function () {
            $stats = ['total_return_percent' => -0.5678];

            $result = $this->formatter->formatStatistics($stats);

            expect($result['Total Return'])->toBe('-0.57%');
        });

        it('uses 2 decimal places for large returns', function () {
            $stats = ['total_return_percent' => 50.123];

            $result = $this->formatter->formatStatistics($stats);

            expect($result['Total Return'])->toBe('50.12%');
        });

        describe('low-trade-count warnings', function () {
            it('adds low-confidence label to Sharpe Ratio when trade count < 30', function () {
                $stats = ['total_trades' => 16, 'sharpe_ratio' => 4.02];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio (low confidence)')
                    ->and($result)->not->toHaveKey('Sharpe Ratio');
            });

            it('adds low-confidence label to Sortino Ratio when trade count < 30', function () {
                $stats = ['total_trades' => 16, 'sortino_ratio' => 6.45];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sortino Ratio (low confidence)')
                    ->and($result)->not->toHaveKey('Sortino Ratio');
            });

            it('omits low-confidence label when trade count >= 30', function () {
                $stats = ['total_trades' => 50, 'sharpe_ratio' => 1.5, 'sortino_ratio' => 2.0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio')
                    ->and($result)->toHaveKey('Sortino Ratio')
                    ->and($result)->not->toHaveKey('Sharpe Ratio (low confidence)')
                    ->and($result)->not->toHaveKey('Sortino Ratio (low confidence)');
            });

            it('omits low-confidence label when trade count is 0', function () {
                $stats = ['total_trades' => 0, 'sharpe_ratio' => 0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio');
            });
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

        describe('additional statistics keys', function () {
            it('formats CAGR as percentage', function () {
                $stats = ['cagr' => 0.0525];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('CAGR')
                    ->and($result['CAGR'])->toBe('5.25%');
            });

            it('formats volatility as annualized percentage', function () {
                $stats = ['volatility' => 0.1234];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Volatility (Ann.)')
                    ->and($result['Volatility (Ann.)'])->toBe('12.34%');
            });

            it('formats trading days as integer', function () {
                $stats = ['trading_days' => 180];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Trading Days')
                    ->and($result['Trading Days'])->toBe('180');
            });

            it('adds warning marker to Total Trades when count is low', function () {
                $stats = ['total_trades' => 16];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Total Trades ⚠')
                    ->and($result)->not->toHaveKey('Total Trades');
            });

            it('does not add warning marker when trade count >= 30', function () {
                $stats = ['total_trades' => 50];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Total Trades')
                    ->and($result)->not->toHaveKey('Total Trades ⚠');
            });

            it('formats time in market as percentage', function () {
                $stats = ['time_in_market_percent' => 12.50];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Time in Market')
                    ->and($result['Time in Market'])->toBe('12.50%');
            });

            it('formats idle capital as percentage', function () {
                $stats = ['idle_capital_percent' => 87.50];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Idle Capital')
                    ->and($result['Idle Capital'])->toBe('87.50%');
            });

            it('hides time in market when zero', function () {
                $stats = ['time_in_market_percent' => 0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->not->toHaveKey('Time in Market');
            });

            it('hides idle capital when zero', function () {
                $stats = ['idle_capital_percent' => 0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->not->toHaveKey('Idle Capital');
            });
        });
    });

    describe('suspicious Sharpe warning', function () {
            it('adds warning when Sharpe > 5 and return < 5%', function () {
                $stats = ['total_trades' => 50, 'sharpe_ratio' => 15.59, 'total_return_percent' => 0.44];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio ⚠')
                    ->and($result['Sharpe Ratio ⚠'])->toContain('high Sharpe with minimal absolute returns');
            });

            it('does not add warning when Sharpe is moderate', function () {
                $stats = ['total_trades' => 50, 'sharpe_ratio' => 1.5, 'total_return_percent' => 2.0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio')
                    ->and($result)->not->toHaveKey('Sharpe Ratio ⚠');
            });

            it('does not add warning when return is high even with high Sharpe', function () {
                $stats = ['total_trades' => 50, 'sharpe_ratio' => 8.0, 'total_return_percent' => 30.0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio')
                    ->and($result)->not->toHaveKey('Sharpe Ratio ⚠');
            });

            it('does not combine low-trade warning and suspicious Sharpe', function () {
                $stats = ['total_trades' => 16, 'sharpe_ratio' => 10.0, 'total_return_percent' => 1.0];

                $result = $this->formatter->formatStatistics($stats);

                expect($result)->toHaveKey('Sharpe Ratio (low confidence) ⚠')
                    ->and($result['Sharpe Ratio (low confidence) ⚠'])->toContain('high Sharpe with minimal absolute returns');
            });
        });

    describe('formatPositions', function () {
        it('formats array positions with color', function () {
            $positions = [
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 50000,
                    'exitPrice' => 55000,
                    'realizedPnl' => 1000,
                    'entryTime' => null,
                    'exitTime' => null,
                ],
            ];

            $result = $this->formatter->formatPositions($positions, 10000);

            expect($result)->toHaveCount(1)
                ->and($result[0][0])->toBe('BTC/USDT')
                ->and($result[0][1])->toBe('long')
                ->and($result[0][2])->toBe('-')
                ->and($result[0][3])->toBe('-')
                ->and($result[0][4])->toBe('-')
                ->and($result[0][5])->toBe('50,000.00')
                ->and($result[0][6])->toBe('55,000.00')
                ->and($result[0][7])->toBe("\033[32m1,000.00\033[0m")
                ->and($result[0][8])->toBe('11,000.00')
                ->and($result[0][9])->toBe('-');
        });

        it('formats object positions with color', function () {
            $position = new stdClass;
            $position->symbol = 'ETH/USDT';
            $position->direction = 'short';
            $position->entryPrice = 3000;
            $position->exitPrice = 2800;
            $position->realizedPnl = -200;
            $position->entryTime = null;
            $position->exitTime = null;

            $result = $this->formatter->formatPositions([$position], 10000);

            expect($result)->toHaveCount(1)
                ->and($result[0][0])->toBe('ETH/USDT')
                ->and($result[0][1])->toBe('short')
                ->and($result[0][7])->toBe("\033[31m-200.00\033[0m")
                ->and($result[0][8])->toBe('9,800.00');
        });

        it('handles missing fields with defaults', function () {
            $positions = [
                ['symbol' => 'BTC/USDT'],
            ];

            $result = $this->formatter->formatPositions($positions, 10000);

            expect($result[0][0])->toBe('BTC/USDT')
                ->and($result[0][1])->toBe('unknown')
                ->and($result[0][2])->toBe('-')
                ->and($result[0][3])->toBe('-')
                ->and($result[0][4])->toBe('-')
                ->and($result[0][5])->toBe('0.00')
                ->and($result[0][6])->toBe('0.00')
                ->and($result[0][7])->toBe("\033[32m0.00\033[0m")
                ->and($result[0][8])->toBe('10,000.00')
                ->and($result[0][9])->toBe('-');
        });

        it('handles missing symbol with question mark', function () {
            $positions = [
                ['direction' => 'long'],
            ];

            $result = $this->formatter->formatPositions($positions, 10000);

            expect($result[0][0])->toBe('?');
        });

        it('returns empty array for no positions', function () {
            $result = $this->formatter->formatPositions([], 10000);

            expect($result)->toBeEmpty();
        });

        it('computes running balance correctly', function () {
            $positions = [
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 50000,
                    'exitPrice' => 55000,
                    'realizedPnl' => 1000,
                ],
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 55000,
                    'exitPrice' => 50000,
                    'realizedPnl' => -500,
                ],
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 50000,
                    'exitPrice' => 52000,
                    'realizedPnl' => 200,
                ],
            ];

            $result = $this->formatter->formatPositions($positions, 10000);

            expect($result)->toHaveCount(3)
                ->and($result[0][8])->toBe('11,000.00')  // 10000 + 1000
                ->and($result[1][8])->toBe('10,500.00')  // 11000 - 500
                ->and($result[2][8])->toBe('10,700.00');  // 10500 + 200
        });

        it('works without initial capital parameter', function () {
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

            expect($result[0][7])->toBe("\033[32m1,000.00\033[0m");
        });

        it('calculates duration for positions with datetime', function () {
            $positions = [
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 50000,
                    'exitPrice' => 55000,
                    'realizedPnl' => 1000,
                    'entryTime' => Carbon::parse('2024-01-01 10:00:00'),
                    'exitTime' => Carbon::parse('2024-01-03 14:30:45'),
                ],
            ];

            $result = $this->formatter->formatPositions($positions, 10000);

            expect($result[0][4])->toBe('2d 4h 30m');
        });

        it('disables color when noColor is true', function () {
            $positions = [
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 50000,
                    'exitPrice' => 55000,
                    'realizedPnl' => 1000,
                ],
                [
                    'symbol' => 'BTC/USDT',
                    'direction' => 'long',
                    'entryPrice' => 55000,
                    'exitPrice' => 50000,
                    'realizedPnl' => -500,
                ],
            ];

            $result = $this->formatter->formatPositions($positions, 10000, true);

            expect($result[0][7])->toBe('1,000.00')
                ->and($result[1][7])->toBe('-500.00');
        });
    });

    describe('formatTradeDistribution', function () {
        it('formats trade distribution from statistics', function () {
            $stats = [
                'total_trades' => 92,
                'winning_trades' => 44,
                'losing_trades' => 48,
                'win_rate' => '0.478',
                'max_consecutive_wins' => 6,
                'max_consecutive_losses' => 4,
                'largest_win' => '500.00',
                'largest_loss' => '-250.00',
                'average_win' => '50.00',
                'average_loss' => '-30.00',
                'expectancy' => '8.26',
                'max_drawdown_duration' => 11,
            ];

            $result = $this->formatter->formatTradeDistribution($stats);

            expect($result)->toHaveKey('Total trades')
                ->and($result['Total trades'])->toBe('92')
                ->and($result)->toHaveKey('Max consecutive wins')
                ->and($result['Max consecutive wins'])->toBe('6')
                ->and($result)->toHaveKey('Max consecutive losses')
                ->and($result['Max consecutive losses'])->toBe('4')
                ->and($result)->toHaveKey('Largest win')
                ->and($result['Largest win'])->toBe('500.00')
                ->and($result)->toHaveKey('Largest loss')
                ->and($result['Largest loss'])->toBe('-250.00')
                ->and($result)->toHaveKey('Average win')
                ->and($result['Average win'])->toBe('50.00')
                ->and($result)->toHaveKey('Average loss')
                ->and($result['Average loss'])->toBe('-30.00')
                ->and($result)->toHaveKey('Expectancy/trade')
                ->and($result['Expectancy/trade'])->toBe('+8.26')
                ->and($result)->toHaveKey('Recovery time (max, bars)')
                ->and($result['Recovery time (max, bars)'])->toBe('11');
        });

        it('adds elapsed time to recovery when timeframe is provided', function () {
            $stats = [
                'total_trades' => 50,
                'max_drawdown_duration' => 150,
            ];

            $result = $this->formatter->formatTradeDistribution($stats, '1h');

            expect($result)->toHaveKey('Recovery time (max, bars)')
                ->and($result['Recovery time (max, bars)'])->toBe('150')
                ->and($result)->toHaveKey('Recovery time (max, elapsed)')
                ->and($result['Recovery time (max, elapsed)'])->toBe('6d 6h');
        });

        it('converts bar recovery to days with 4h timeframe', function () {
            $stats = [
                'total_trades' => 50,
                'max_drawdown_duration' => 42,
            ];

            $result = $this->formatter->formatTradeDistribution($stats, '4h');

            expect($result)->toHaveKey('Recovery time (max, elapsed)')
                ->and($result['Recovery time (max, elapsed)'])->toBe('7d');
        });

        it('shows only minutes for short recovery with 5m timeframe', function () {
            $stats = [
                'total_trades' => 50,
                'max_drawdown_duration' => 30,
            ];

            $result = $this->formatter->formatTradeDistribution($stats, '5m');

            expect($result)->toHaveKey('Recovery time (max, elapsed)')
                ->and($result['Recovery time (max, elapsed)'])->toBe('2h 30m');
        });

        it('omits elapsed recovery when timeframe is null', function () {
            $stats = [
                'total_trades' => 50,
                'max_drawdown_duration' => 10,
            ];

            $result = $this->formatter->formatTradeDistribution($stats);

            expect($result)->toHaveKey('Recovery time (max, bars)')
                ->and($result)->not->toHaveKey('Recovery time (max, elapsed)');
        });

        it('formats negative expectancy correctly', function () {
            $stats = [
                'total_trades' => 50,
                'winning_trades' => 20,
                'losing_trades' => 30,
                'win_rate' => '0.4',
                'expectancy' => '-3.50',
            ];

            $result = $this->formatter->formatTradeDistribution($stats);

            expect($result['Expectancy/trade'])->toBe('-3.50');
        });

        it('computes average win streak from win rate', function () {
            $stats = [
                'total_trades' => 100,
                'winning_trades' => 50,
                'losing_trades' => 50,
                'win_rate' => '0.5',
            ];

            $result = $this->formatter->formatTradeDistribution($stats);

            expect($result)->toHaveKey('Average win streak')
                ->and((float) $result['Average win streak'])->toBeGreaterThan(1.9)
                ->and((float) $result['Average win streak'])->toBeLessThan(2.1)
                ->and($result)->toHaveKey('Average loss streak')
                ->and((float) $result['Average loss streak'])->toBeGreaterThan(1.9)
                ->and((float) $result['Average loss streak'])->toBeLessThan(2.1);
        });

        it('handles 100% win rate', function () {
            $stats = [
                'total_trades' => 10,
                'winning_trades' => 10,
                'losing_trades' => 0,
                'win_rate' => '1.0',
            ];

            $result = $this->formatter->formatTradeDistribution($stats);

            expect($result)->toHaveKey('Average loss streak')
                ->and($result['Average loss streak'])->toBe('0.0');
        });

        it('handles 0% win rate', function () {
            $stats = [
                'total_trades' => 10,
                'winning_trades' => 0,
                'losing_trades' => 10,
                'win_rate' => '0.0',
            ];

            $result = $this->formatter->formatTradeDistribution($stats);

            expect($result)->toHaveKey('Average win streak')
                ->and($result['Average win streak'])->toBe('0.0');
        });

        it('returns empty array for empty stats', function () {
            $result = $this->formatter->formatTradeDistribution([]);

            expect($result)->toHaveKey('Total trades')
                ->and($result['Total trades'])->toBe('0');
        });
    });

    describe('formatExitReasonDistribution', function () {
        it('counts exit reasons from position array', function () {
            $positions = [
                ['exitTag' => 'take_profit'],
                ['exitTag' => 'take_profit'],
                ['exitTag' => 'stop_loss'],
                ['exitTag' => 'strategy_signal'],
                ['exitTag' => 'take_profit'],
            ];

            $result = $this->formatter->formatExitReasonDistribution($positions);

            expect($result)->toHaveCount(3);

            $labels = array_keys($result);
            expect($labels)->toContain('Take Profit')
                ->and($labels)->toContain('Stop Loss')
                ->and($labels)->toContain('Strategy Signal');

            expect($result['Take Profit']['count'])->toBe(3)
                ->and($result['Take Profit']['pct'])->toBe(60.0)
                ->and($result['Stop Loss']['count'])->toBe(1)
                ->and($result['Stop Loss']['pct'])->toBe(20.0)
                ->and($result['Strategy Signal']['count'])->toBe(1)
                ->and($result['Strategy Signal']['pct'])->toBe(20.0);
        });

        it('handles object positions', function () {
            $p1 = new stdClass;
            $p1->exitTag = 'stop_loss';
            $p2 = new stdClass;
            $p2->exitTag = 'take_profit';

            $result = $this->formatter->formatExitReasonDistribution([$p1, $p2]);

            expect($result['Stop Loss']['count'])->toBe(1)
                ->and($result['Take Profit']['count'])->toBe(1);
        });

        it('normalises exit tag labels', function () {
            $positions = [
                ['exitTag' => 'counter_signal'],
                ['exitTag' => 'end_of_backtest'],
                ['exitTag' => 'trailing_stop'],
            ];

            $result = $this->formatter->formatExitReasonDistribution($positions);

            expect(array_keys($result))->toContain('Counter Signal')
                ->and(array_keys($result))->toContain('End of Backtest')
                ->and(array_keys($result))->toContain('Trailing Stop');
        });

        it('uses raw exit tag when no mapping exists', function () {
            $positions = [
                ['exitTag' => 'custom_exit'],
            ];

            $result = $this->formatter->formatExitReasonDistribution($positions);

            expect(array_keys($result))->toContain('custom_exit')
                ->and($result['custom_exit']['count'])->toBe(1);
        });

        it('returns empty array for empty positions', function () {
            $result = $this->formatter->formatExitReasonDistribution([]);

            expect($result)->toBeEmpty();
        });

        it('sorts by count descending', function () {
            $positions = [
                ['exitTag' => 'strategy_signal'],
                ['exitTag' => 'take_profit'],
                ['exitTag' => 'take_profit'],
                ['exitTag' => 'stop_loss'],
                ['exitTag' => 'stop_loss'],
                ['exitTag' => 'stop_loss'],
            ];

            $result = $this->formatter->formatExitReasonDistribution($positions);

            $keys = array_keys($result);
            expect($keys[0])->toBe('Stop Loss')
                ->and($keys[1])->toBe('Take Profit')
                ->and($keys[2])->toBe('Strategy Signal');
        });

        it('handles missing exitTag', function () {
            $positions = [
                ['symbol' => 'BTC/USDT'],
            ];

            $result = $this->formatter->formatExitReasonDistribution($positions);

            expect(array_keys($result))->toContain('unknown')
                ->and($result['unknown']['count'])->toBe(1);
        });
    });
});
