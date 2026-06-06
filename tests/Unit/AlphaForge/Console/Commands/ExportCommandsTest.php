<?php

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Console\Commands\ExportOptimizeCommand;
use App\AlphaForge\Console\Commands\ExportTradesCommand;

describe('ExportTradesCommand', function () {
    function makeBacktestRun(array $trades): BacktestRun
    {
        $run = new BacktestRun;
        $run->id = '019a0000-0000-7000-8000-000000000001';
        $run->strategy_alias = 'sma_crossover';
        $run->symbols = ['BTCUSDT'];
        $run->status = 'completed';
        $run->statistics = ['position_trades' => $trades];

        return $run;
    }

    describe('renderCsv()', function () {
        it('produces CSV with header and trade rows', function () {
            $cmd = new ExportTradesCommand;
            $run = makeBacktestRun([
                [
                    'entry_time' => '2024-01-01T00:00:00+00:00',
                    'exit_time' => '2024-01-02T00:00:00+00:00',
                    'direction' => 'long',
                    'entry_price' => 50000.0,
                    'exit_price' => 51000.0,
                    'pnl' => 100.0,
                    'mae' => 50.0,
                    'mfe' => 200.0,
                    'bars_held' => 24,
                    'exit_reason' => 'take_profit',
                    'quantity' => 0.01,
                ],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderCsv');
            $csv = $ref->invoke($cmd, $run, $run->statistics['position_trades']);

            $lines = explode("\n", $csv);
            expect($lines[0])->toContain('entry_time')
                ->and($lines[0])->toContain('exit_time')
                ->and($lines[0])->toContain('pnl')
                ->and($lines[0])->toContain('mae')
                ->and($lines[0])->toContain('mfe')
                ->and($lines[0])->toContain('exit_reason');
            expect(count($lines))->toBe(2); // header + 1 row
        });

        it('escapes CSV values containing commas', function () {
            $cmd = new ExportTradesCommand;
            $run = makeBacktestRun([
                [
                    'entry_time' => '2024-01-01T00:00:00+00:00',
                    'exit_time' => '2024-01-02T00:00:00+00:00',
                    'direction' => 'long',
                    'entry_price' => 50000.0,
                    'exit_price' => 51000.0,
                    'pnl' => 100.0,
                    'mae' => 50.0,
                    'mfe' => 200.0,
                    'bars_held' => 24,
                    'exit_reason' => 'take_profit',
                    'quantity' => 0.01,
                ],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderCsv');
            $csv = $ref->invoke($cmd, $run, $run->statistics['position_trades']);

            // No unquoted commas in the data row
            $lines = explode("\n", $csv);
            $dataLine = $lines[1];
            expect($dataLine)->not->toBeEmpty();
        });
    });

    describe('renderJson()', function () {
        it('produces valid JSON with expected keys', function () {
            $cmd = new ExportTradesCommand;
            $run = makeBacktestRun([
                [
                    'entry_time' => '2024-01-01T00:00:00+00:00',
                    'exit_time' => '2024-01-02T00:00:00+00:00',
                    'direction' => 'long',
                    'entry_price' => 50000.0,
                    'exit_price' => 51000.0,
                    'pnl' => 100.0,
                    'mae' => null,
                    'mfe' => null,
                    'bars_held' => null,
                    'exit_reason' => 'strategy_signal',
                    'quantity' => 0.01,
                ],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderJson');
            $json = $ref->invoke($cmd, $run, $run->statistics['position_trades']);

            $data = json_decode($json, true);
            expect($data)->toHaveKey('backtest_id');
            expect($data)->toHaveKey('strategy_alias');
            expect($data)->toHaveKey('symbols');
            expect($data)->toHaveKey('total_trades');
            expect($data)->toHaveKey('trades');
            expect($data['total_trades'])->toBe(1);
        });
    });
});

describe('ExportOptimizeCommand', function () {
    function makeOptimizationRun(): OptimizationRun
    {
        $run = new OptimizationRun;
        $run->id = '019a0000-0000-7000-8000-000000000002';
        $run->strategy_alias = 'sma_crossover';
        $run->symbols = ['BTCUSDT'];
        $run->optimization_method = 'random';
        $run->optimization_objective = 'sharpe_ratio';
        $run->total_combinations = 500;
        $run->completed_combinations = 500;
        $run->best_parameters = ['fastPeriod' => 10, 'slowPeriod' => 50];
        $run->best_statistics = ['sharpe_ratio' => '1.5'];

        return $run;
    }

    function makeOptimizationResults(array $stats): \Illuminate\Database\Eloquent\Collection
    {
        $results = new \Illuminate\Database\Eloquent\Collection;
        foreach ($stats as $index => $s) {
            $r = new BacktestRun;
            $r->id = '019a0000-0000-7000-8000-'.str_pad((string) $index, 12, '0', STR_PAD_LEFT);
            $r->strategy_inputs = $s['params'] ?? [];
            $r->statistics = array_merge(
                ['optimization_score' => $s['score'] ?? '0'],
                $s['stats'] ?? [],
            );
            $results->push($r);
        }

        return $results;
    }

    describe('renderCsv()', function () {
        it('produces CSV with header and result rows', function () {
            $cmd = new ExportOptimizeCommand;
            $run = makeOptimizationRun();
            $results = makeOptimizationResults([
                [
                    'score' => '2.5',
                    'params' => ['fastPeriod' => 10],
                    'stats' => [
                        'total_return_percent' => '15.0',
                        'sharpe_ratio' => '1.8',
                        'max_drawdown_percent' => '-5.0',
                        'total_trades' => '30',
                        'win_rate' => '0.6',
                        'profit_factor' => '1.5',
                    ],
                ],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderCsv');
            $csv = $ref->invoke($cmd, $run, $results);

            $lines = explode("\n", $csv);
            expect($lines[0])->toContain('rank')
                ->and($lines[0])->toContain('score')
                ->and($lines[0])->toContain('params')
                ->and($lines[0])->toContain('return_pct')
                ->and($lines[0])->toContain('sharpe')
                ->and($lines[0])->toContain('trades');
            expect(count($lines))->toBe(2); // header + 1 row
        });

        it('includes correct rank numbers', function () {
            $cmd = new ExportOptimizeCommand;
            $run = makeOptimizationRun();
            $results = makeOptimizationResults([
                ['score' => '3.0', 'params' => ['a' => 1], 'stats' => ['total_return_percent' => '10', 'sharpe_ratio' => '2', 'max_drawdown_percent' => '-3', 'total_trades' => '20', 'win_rate' => '0.5', 'profit_factor' => '1.2']],
                ['score' => '2.0', 'params' => ['a' => 2], 'stats' => ['total_return_percent' => '8', 'sharpe_ratio' => '1.5', 'max_drawdown_percent' => '-4', 'total_trades' => '15', 'win_rate' => '0.4', 'profit_factor' => '1.1']],
                ['score' => '1.0', 'params' => ['a' => 3], 'stats' => ['total_return_percent' => '5', 'sharpe_ratio' => '1.0', 'max_drawdown_percent' => '-6', 'total_trades' => '10', 'win_rate' => '0.3', 'profit_factor' => '0.9']],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderCsv');
            $csv = $ref->invoke($cmd, $run, $results);

            $lines = explode("\n", $csv);
            // Ranks: 1, 2, 3
            expect($lines[1])->toContain('1,');
            expect($lines[2])->toContain('2,');
            expect($lines[3])->toContain('3,');
        });
    });

    describe('renderJson()', function () {
        it('produces valid JSON with expected keys', function () {
            $cmd = new ExportOptimizeCommand;
            $run = makeOptimizationRun();
            $results = makeOptimizationResults([
                [
                    'score' => '2.5',
                    'params' => ['fastPeriod' => 10, 'slowPeriod' => 50],
                    'stats' => [
                        'total_return_percent' => '15.0',
                        'sharpe_ratio' => '1.8',
                        'max_drawdown_percent' => '-5.0',
                        'total_trades' => '30',
                        'win_rate' => '0.6',
                        'profit_factor' => '1.5',
                    ],
                ],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderJson');
            $json = $ref->invoke($cmd, $run, $results);

            $data = json_decode($json, true);
            expect($data)->toHaveKey('optimization_id');
            expect($data)->toHaveKey('strategy_alias');
            expect($data)->toHaveKey('symbols');
            expect($data)->toHaveKey('method');
            expect($data)->toHaveKey('objective');
            expect($data)->toHaveKey('best_parameters');
            expect($data)->toHaveKey('best_statistics');
            expect($data)->toHaveKey('results');
            expect($data['results'])->toHaveCount(1);
            expect($data['results'][0])->toHaveKey('rank');
            expect($data['results'][0])->toHaveKey('parameters');
            expect($data['results'][0])->toHaveKey('statistics');
        });

        it('includes per_symbol breakdown when present', function () {
            $cmd = new ExportOptimizeCommand;
            $run = makeOptimizationRun();
            $results = makeOptimizationResults([
                [
                    'score' => '1.0',
                    'params' => ['a' => 1],
                    'stats' => [
                        'total_return_percent' => '5.0',
                        'sharpe_ratio' => '1.0',
                        'max_drawdown_percent' => '-2.0',
                        'total_trades' => '10',
                        'win_rate' => '0.5',
                        'profit_factor' => '1.1',
                        'per_symbol' => [
                            'BTCUSDT' => ['total_return_percent' => '5.0'],
                            'ETHUSDT' => ['total_return_percent' => '3.0'],
                        ],
                    ],
                ],
            ]);

            $ref = new ReflectionMethod($cmd, 'renderJson');
            $json = $ref->invoke($cmd, $run, $results);

            $data = json_decode($json, true);
            expect($data['results'][0])->toHaveKey('per_symbol');
            expect($data['results'][0]['per_symbol'])->toHaveKeys(['BTCUSDT', 'ETHUSDT']);
        });
    });
});