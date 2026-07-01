<?php

use Illuminate\Console\Command;

describe('HasJsonOutput trait', function () {
    describe('jsonEnabled() via real commands', function () {
        it('returns json output when --json is passed', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:data:export', [
                'exchange' => 'binance',
                'market' => 'BTC/USDT',
                'timeframe' => '1h',
                '--json' => true,
            ]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            $data = json_decode($raw, true);
            expect(json_last_error())->toBe(JSON_ERROR_NONE);
            expect($data)->toHaveKeys(['command', 'success', 'data', 'error']);
            expect($data['success'])->toBeFalse();
            expect($data['error'])->toContain('not yet implemented');
        });

        it('outputs text error without --json', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:data:export', [
                'exchange' => 'binance',
                'market' => 'BTC/USDT',
                'timeframe' => '1h',
            ]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            expect($raw)->toContain('Export is not yet implemented.');
            $data = json_decode($raw, true);
            expect($data)->toBeNull();
        });
    });

    describe('outputJson() uniform wrapper', function () {
        it('has all four wrapper keys on success', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:strategies:list', ['--json' => true]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            $data = json_decode($raw, true);

            expect(json_last_error())->toBe(JSON_ERROR_NONE);
            expect($data)->toHaveKeys(['command', 'success', 'data', 'error']);
            expect($data['success'])->toBeTrue();
            expect($data['error'])->toBeNull();
            expect($data['data']['strategies'])->toBeArray()->not->toBeEmpty();
        });

        it('has all four wrapper keys on error', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:optimizations:show', [
                'optimization_id' => '00000000-0000-0000-0000-000000000000',
                '--json' => true,
            ]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            $data = json_decode($raw, true);

            expect(json_last_error())->toBe(JSON_ERROR_NONE);
            expect($data)->toHaveKeys(['command', 'success', 'data', 'error']);
            expect($data['success'])->toBeFalse();
            expect($data['data'])->toBeNull();
            expect($data['error'])->toContain('not found');
        });
    });

    describe('validateJsonFormatConflict', function () {
        it('errors when --json and --format are used together', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:export:backtest', [
                'backtest_id' => '00000000-0000-0000-0000-000000000000',
                '--json' => true,
                '--format' => 'csv',
            ]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            $data = json_decode($raw, true);

            expect(json_last_error())->toBe(JSON_ERROR_NONE);
            expect($data['error'])->toContain('--json and --format together');
        });

        it('errors when --format is explicitly passed with --json', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:walk-forward:show', [
                'run_id' => '00000000-0000-0000-0000-000000000000',
                '--json' => true,
                '--format' => 'table',
            ]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            $data = json_decode($raw, true);

            expect(json_last_error())->toBe(JSON_ERROR_NONE);
            expect($data['error'])->toContain('--json and --format together');
        });
    });

    describe('debugMemory skips output in --json mode', function () {
        it('does not output memory info with --json', function () {
            $code = $this->app['Illuminate\Contracts\Console\Kernel']->call('alphaforge:backtest:debug', [
                'strategy' => 'sma_crossover',
                'symbol' => 'BTCUSDT',
                '--json' => true,
            ]);
            $raw = $this->app['Illuminate\Contracts\Console\Kernel']->output();

            expect($raw)->not->toContain('peak memory');
            expect($raw)->not->toContain('debug:');

            $data = json_decode($raw, true);
            expect(json_last_error())->toBe(JSON_ERROR_NONE);
        });
    });
});
