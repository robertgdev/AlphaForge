<?php

use App\AlphaForge\Backtesting\Service\StatisticsService;
use App\AlphaForge\Order\Dto\PositionDto;
use Carbon\Carbon;
use Ds\Vector;

describe('StatisticsService', function () {
    beforeEach(function () {
        $this->service = new StatisticsService;
    });

    describe('empty positions', function () {
        it('returns empty statistics when no closed positions', function () {
            $positions = new Vector;

            $result = $this->service->calculate($positions, '10000', '10000');

            expect($result['total_trades'])->toBe(0)
                ->and($result['winning_trades'])->toBe(0)
                ->and($result['losing_trades'])->toBe(0)
                ->and($result['win_rate'])->toBe('0')
                ->and($result['initial_capital'])->toBe('0')
                ->and($result['final_capital'])->toBe('0')
                ->and($result['sharpe_ratio'])->toBe('0')
                ->and($result['sortino_ratio'])->toBe('0')
                ->and($result['max_drawdown'])->toBe('0');
        });

        it('returns empty statistics when positions have no exit time', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                realizedPnl: '0',
            ));

            $result = $this->service->calculate($positions, '10000', '10000');

            expect($result['total_trades'])->toBe(0)
                ->and($result['winning_trades'])->toBe(0);
        });
    });

    describe('capital metrics', function () {
        it('calculates total return', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));

            $result = $this->service->calculate($positions, '10000', '11000');

            expect(bccomp($result['total_return'], '1000', 8))->toBe(0)
                ->and(bccomp($result['total_return_percent'], '10.0', 2))->toBe(0);
        });
    });

    describe('win/loss metrics', function () {
        it('counts winning and losing trades', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-200',
            ));
            $positions->push(new PositionDto(
                id: '3',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '54000',
                entryTime: Carbon::parse('2024-01-05'),
                exitPrice: '56000',
                exitTime: Carbon::parse('2024-01-06'),
                realizedPnl: '200',
            ));

            $result = $this->service->calculate($positions, '10000', '11000');

            expect($result['winning_trades'])->toBe(2)
                ->and($result['losing_trades'])->toBe(1);
        });

        it('calculates win rate', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            expect(bccomp($result['win_rate'], '0.5', 4))->toBe(0);
        });

        it('calculates gross profit and gross loss', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            expect(bccomp($result['gross_profit'], '1000', 8))->toBe(0)
                ->and(bccomp($result['gross_loss'], '-200', 8))->toBe(0);
        });

        it('calculates net profit', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            expect(bccomp($result['net_profit'], '800', 8))->toBe(0);
        });

        it('calculates profit factor', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            $expectedFactor = bcdiv('1000', '200', 4);
            expect(bccomp($result['profit_factor'], $expectedFactor, 2))->toBe(0);
        });

        it('returns INF profit factor when no losses but has profits', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));

            $result = $this->service->calculate($positions, '10000', '11000');

            expect($result['profit_factor'])->toBe('INF');
        });

        it('tracks largest win and largest loss', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '54000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '56000',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '400',
            ));
            $positions->push(new PositionDto(
                id: '3',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-05'),
                exitPrice: '2700',
                exitTime: Carbon::parse('2024-01-06'),
                realizedPnl: '-300',
            ));

            $result = $this->service->calculate($positions, '10000', '11100');

            expect(bccomp($result['largest_win'], '1000', 8))->toBe(0)
                ->and(bccomp($result['largest_loss'], '-300', 8))->toBe(0);
        });
    });

    describe('trade analysis', function () {
        it('counts long and short trades separately', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'short',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '200',
            ));

            $result = $this->service->calculate($positions, '10000', '11200');

            expect($result['long_trades'])->toBe(1)
                ->and($result['short_trades'])->toBe(1);
        });

        it('calculates long and short win rates', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '54000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '52000',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-400',
            ));
            $positions->push(new PositionDto(
                id: '3',
                symbol: 'ETH/USDT',
                direction: 'short',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-05'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-06'),
                realizedPnl: '200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            expect(bccomp($result['long_win_rate'], '0.5', 4))->toBe(0)
                ->and(bccomp($result['short_win_rate'], '1', 4))->toBe(0);
        });

        it('tracks max consecutive wins and losses', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '54000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '56000',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '400',
            ));
            $positions->push(new PositionDto(
                id: '3',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-05'),
                exitPrice: '2700',
                exitTime: Carbon::parse('2024-01-06'),
                realizedPnl: '-300',
            ));
            $positions->push(new PositionDto(
                id: '4',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '2700',
                entryTime: Carbon::parse('2024-01-07'),
                exitPrice: '2500',
                exitTime: Carbon::parse('2024-01-08'),
                realizedPnl: '-200',
            ));
            $positions->push(new PositionDto(
                id: '5',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '2500',
                entryTime: Carbon::parse('2024-01-09'),
                exitPrice: '2200',
                exitTime: Carbon::parse('2024-01-10'),
                realizedPnl: '-300',
            ));

            $result = $this->service->calculate($positions, '10000', '10600');

            expect($result['max_consecutive_wins'])->toBe(2)
                ->and($result['max_consecutive_losses'])->toBe(3);
        });

        it('calculates average trade duration', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01 10:00:00'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-01 14:00:00'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-02 10:00:00'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-02 18:00:00'),
                realizedPnl: '-200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            expect($result['average_trade_duration'])->toBe(21600);
        });
    });

    describe('drawdown', function () {
        it('calculates max drawdown from equity curve', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '2700',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '-500',
            ));
            $positions->push(new PositionDto(
                id: '3',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '53000',
                entryTime: Carbon::parse('2024-01-05'),
                exitPrice: '56000',
                exitTime: Carbon::parse('2024-01-06'),
                realizedPnl: '600',
            ));

            $result = $this->service->calculate($positions, '10000', '11100');

            expect(bccomp($result['max_drawdown'], '500', 8))->toBe(0);
        });

        it('returns zero drawdown when equity only increases', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '54000',
                entryTime: Carbon::parse('2024-01-03'),
                exitPrice: '56000',
                exitTime: Carbon::parse('2024-01-04'),
                realizedPnl: '400',
            ));

            $result = $this->service->calculate($positions, '10000', '11400');

            expect(bccomp($result['max_drawdown'], '0', 8))->toBe(0);
        });
    });

    describe('time metrics', function () {
        it('calculates trading days between first and last trade', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-05'),
                realizedPnl: '1000',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'ETH/USDT',
                direction: 'long',
                quantity: '1',
                entryPrice: '3000',
                entryTime: Carbon::parse('2024-01-06'),
                exitPrice: '2800',
                exitTime: Carbon::parse('2024-01-15'),
                realizedPnl: '-200',
            ));

            $result = $this->service->calculate($positions, '10000', '10800');

            expect($result['trading_days'])->toBe(10.0);
        });
    });

    describe('zero pnl trades', function () {
        it('does not count break-even trades as wins or losses', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '50000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '0',
            ));

            $result = $this->service->calculate($positions, '10000', '10000');

            expect($result['winning_trades'])->toBe(0)
                ->and($result['losing_trades'])->toBe(0);
        });
    });

    describe('risk metrics', function () {
        it('returns zero sharpe and sortino when fewer than 10 observations', function () {
            $positions = new Vector;
            for ($i = 0; $i < 9; $i++) {
                $positions->push(new PositionDto(
                    id: (string) $i,
                    symbol: 'BTC/USDT',
                    direction: 'long',
                    quantity: '0.01',
                    entryPrice: '50000',
                    entryTime: Carbon::parse('2024-01-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)),
                    exitPrice: (string) (51000 + $i * 100),
                    exitTime: Carbon::parse('2024-01-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT)),
                    realizedPnl: (string) (100 + $i * 50),
                ));
            }

            $result = $this->service->calculate($positions, '10000', '12000');

            expect($result['sharpe_ratio'])->toBe('0')
                ->and($result['sortino_ratio'])->toBe('0');
        });

        it('computes non-zero sharpe and sortino with enough observations', function () {
            $positions = new Vector;
            for ($i = 0; $i < 10; $i++) {
                $positions->push(new PositionDto(
                    id: (string) $i,
                    symbol: 'BTC/USDT',
                    direction: 'long',
                    quantity: '0.01',
                    entryPrice: '50000',
                    entryTime: Carbon::parse('2024-01-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)),
                    exitPrice: (string) (51000 + $i * 100),
                    exitTime: Carbon::parse('2024-01-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT)),
                    realizedPnl: $i % 2 === 0 ? '200' : '-100',
                ));
            }

            $result = $this->service->calculate($positions, '10000', '10500');

            expect(bccomp($result['sharpe_ratio'], '0', 6))->not->toBe(0)
                ->and(bccomp($result['sortino_ratio'], '0', 6))->not->toBe(0);
        });

        it('returns zero ratios when volatility is too low', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '51000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '100',
            ));

            $constantEquity = new Vector;
            for ($i = 0; $i < 11; $i++) {
                $constantEquity->push('100');
            }

            $result = $this->service->calculate($positions, '10000', '10100', null, 252, $constantEquity);

            expect($result['sharpe_ratio'])->toBe('0')
                ->and($result['sortino_ratio'])->toBe('0');
        });

        it('computes different sharpe and sortino when downside returns differ', function () {
            $positions = new Vector;
            $pnls = ['500', '-50', '400', '-30', '300', '-80', '200', '-20', '100', '-10'];
            foreach ($pnls as $i => $pnl) {
                $positions->push(new PositionDto(
                    id: (string) $i,
                    symbol: 'BTC/USDT',
                    direction: 'long',
                    quantity: '0.01',
                    entryPrice: '50000',
                    entryTime: Carbon::parse('2024-01-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)),
                    exitPrice: '51000',
                    exitTime: Carbon::parse('2024-01-'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT)),
                    realizedPnl: $pnl,
                ));
            }

            $result = $this->service->calculate($positions, '10000', '11310');

            expect(bccomp($result['sharpe_ratio'], '0', 6))->not->toBe(0)
                ->and(bccomp($result['sortino_ratio'], '0', 6))->not->toBe(0)
                ->and($result['sharpe_ratio'])->not->toBe($result['sortino_ratio']);
        });
    });

    describe('CAGR', function () {
        it('returns a small CAGR for a tiny return over months', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '50050',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '7.00',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '51000',
                entryTime: Carbon::parse('2024-06-01'),
                exitPrice: '51050',
                exitTime: Carbon::parse('2024-06-13'),
                realizedPnl: '7.15',
            ));

            $result = $this->service->calculate($positions, '10000', '10014.15');

            $cagr = (float) $result['cagr'];
            expect($cagr)->toBeGreaterThan(0.001)
                ->and($cagr)->toBeLessThan(0.005);
        });

        it('returns zero CAGR when trading days is zero', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '51000',
                exitTime: Carbon::parse('2024-01-01'),
                realizedPnl: '100',
            ));

            $result = $this->service->calculate($positions, '10000', '10100');

            expect($result['cagr'])->toBe('0');
        });

        it('computes positive CAGR for a positive annual return', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '51000',
                exitTime: Carbon::parse('2024-01-15'),
                realizedPnl: '500',
            ));
            $positions->push(new PositionDto(
                id: '2',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '51000',
                entryTime: Carbon::parse('2024-06-01'),
                exitPrice: '52000',
                exitTime: Carbon::parse('2025-01-01'),
                realizedPnl: '500',
            ));

            $result = $this->service->calculate($positions, '10000', '11000');

            $cagr = (float) $result['cagr'];
            // 10% return over ~1 year should be close to 10%
            expect($cagr)->toBeGreaterThan(0.08)
                ->and($cagr)->toBeLessThan(0.12);
        });

        it('returns zero CAGR when initial capital is zero', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '51000',
                exitTime: Carbon::parse('2024-06-13'),
                realizedPnl: '100',
            ));

            $result = $this->service->calculate($positions, '0', '100');

            expect($result['cagr'])->toBe('0');
        });

        it('returns zero CAGR when final capital equals initial capital', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.01',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '50000',
                exitTime: Carbon::parse('2024-06-13'),
                realizedPnl: '0',
            ));

            $result = $this->service->calculate($positions, '10000', '10000');

            expect($result['cagr'])->toBe('0');
        });
    });

    describe('exposure metrics', function () {
        it('computes time in market and idle capital from bar equity curve', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));

            // 6 bars, 2 with equity change = 40% time in market
            $barEquity = new Vector;
            $barEquity->push('10000');   // bar 0
            $barEquity->push('10000');   // idle
            $barEquity->push('10500');   // active (position open)
            $barEquity->push('10500');   // idle
            $barEquity->push('11000');   // active
            $barEquity->push('11000');   // idle

            $result = $this->service->calculate($positions, '10000', '11000', null, 252, $barEquity);

            expect(bccomp($result['time_in_market_percent'], '40.0000', 2))->toBe(0)
                ->and((float) $result['idle_capital_percent'])->toBeGreaterThan(50.0);
        });

        it('returns zero exposure when no bar equity curve provided', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));

            $result = $this->service->calculate($positions, '10000', '11000');

            expect($result['time_in_market_percent'])->toBe('0')
                ->and($result['idle_capital_percent'])->toBe('0');
        });

        it('returns full time in market when every bar has equity change', function () {
            $positions = new Vector;
            $positions->push(new PositionDto(
                id: '1',
                symbol: 'BTC/USDT',
                direction: 'long',
                quantity: '0.02',
                entryPrice: '50000',
                entryTime: Carbon::parse('2024-01-01'),
                exitPrice: '55000',
                exitTime: Carbon::parse('2024-01-02'),
                realizedPnl: '1000',
            ));

            $barEquity = new Vector;
            for ($i = 0; $i <= 12; $i++) {
                $barEquity->push((string) (10000 + $i * 100));
            }

            $result = $this->service->calculate($positions, '10000', '11200', null, 252, $barEquity);

            expect(bccomp($result['time_in_market_percent'], '100', 2))->toBe(0)
                ->and(bccomp($result['idle_capital_percent'], '0', 2))->toBe(0);
        });
    });
});
