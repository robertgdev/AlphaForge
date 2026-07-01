<?php

describe('alphaforge:schema', function () {
    function runSchemaCommand(?string $commandName = null): array
    {
        $args = $commandName ? ['name' => $commandName] : [];

        app('Illuminate\Contracts\Console\Kernel')->call('alphaforge:schema', $args);
        $raw = app('Illuminate\Contracts\Console\Kernel')->output();

        return json_decode($raw, true);
    }

    describe('single command schema', function () {
        it('returns schema version and command name', function () {
            $data = runSchemaCommand('alphaforge:data:import');

            expect($data['success'])->toBeTrue();
            expect($data['data']['schema'])->toBe('alphaforge.help.v1');
            expect($data['data']['command'])->toBe('alphaforge:data:import');
            expect($data['data']['description'])->toContain('Import');
        });

        it('returns arguments with name, type, required fields', function () {
            $data = runSchemaCommand('alphaforge:data:import');
            $args = $data['data']['arguments'];

            expect($args)->toHaveCount(5);

            foreach ($args as $arg) {
                expect($arg)->toHaveKeys(['name', 'type', 'required', 'description']);
            }

            // exchange is required
            $exchange = collect($args)->firstWhere('name', 'exchange');
            expect($exchange['required'])->toBeTrue();
            expect($exchange['type'])->toBe('string');
            expect($exchange)->toHaveKey('examples');
            expect($exchange['examples'])->toContain('binance');

            // enddate is optional
            $enddate = collect($args)->firstWhere('name', 'enddate');
            expect($enddate['required'])->toBeFalse();
        });

        it('enriches timeframes with allowed_values from TimeframeEnum', function () {
            $data = runSchemaCommand('alphaforge:data:import');
            $args = $data['data']['arguments'];

            $timeframe = collect($args)->firstWhere('name', 'timeframe');
            expect($timeframe)->toHaveKey('allowed_values');
            expect($timeframe['allowed_values'])->toContain('1m');
            expect($timeframe['allowed_values'])->toContain('1h');
            expect($timeframe['allowed_values'])->toContain('1d');
            expect($timeframe['allowed_values'])->toContain('1w');
        });

        it('enriches dates with format field', function () {
            $data = runSchemaCommand('alphaforge:data:import');
            $args = $data['data']['arguments'];

            $startdate = collect($args)->firstWhere('name', 'startdate');
            expect($startdate['type'])->toBe('date');
            expect($startdate['format'])->toBe('YYYY-MM-DD');
            expect($startdate)->toHaveKey('examples');
            expect($startdate['examples'])->toContain('2024-01-01');
        });

        it('enriches market/symbol with pattern field', function () {
            $data = runSchemaCommand('alphaforge:data:import');
            $args = $data['data']['arguments'];

            $market = collect($args)->firstWhere('name', 'market');
            expect($market)->toHaveKey('pattern');
            expect($market['pattern'])->toBe('BASE/QUOTE');
        });
    });

    describe('complex command schemas', function () {
        it('returns options with defaults for walk-forward', function () {
            $data = runSchemaCommand('alphaforge:walk-forward');
            $opts = $data['data']['options'];

            expect($opts)->not->toBeEmpty();

            // exchange option has default
            $exchange = collect($opts)->firstWhere('name', 'exchange');
            expect($exchange['default'])->toBe('binance');

            // method has allowed_values from enum
            $method = collect($opts)->firstWhere('name', 'method');
            expect($method)->toHaveKey('allowed_values');
            expect($method['allowed_values'])->toContain('random');
            expect($method['allowed_values'])->toContain('grid');
            expect($method['allowed_values'])->toContain('genetic');

            // runner has allowed_values
            $runner = collect($opts)->firstWhere('name', 'runner');
            expect($runner['allowed_values'])->toContain('fork');
            expect($runner['allowed_values'])->toContain('sync');
        });

        it('enriches strategy with examples from registry', function () {
            $data = runSchemaCommand('alphaforge:optimize');
            $args = $data['data']['arguments'];

            $strategy = collect($args)->firstWhere('name', 'strategy');
            expect($strategy)->toHaveKey('examples');
            expect($strategy['examples'])->toContain('sma_crossover');
        });

        it('marks boolean flags correctly', function () {
            $data = runSchemaCommand('alphaforge:data:import');
            $opts = $data['data']['options'];

            $force = collect($opts)->firstWhere('name', 'force');
            expect($force['type'])->toBe('boolean');

            $debug = collect($opts)->firstWhere('name', 'debug');
            expect($debug['type'])->toBe('boolean');
        });

        it('adds minimum/maximum for range parameters', function () {
            $data = runSchemaCommand('alphaforge:walk-forward');
            $opts = $data['data']['options'];

            $split = collect($opts)->firstWhere('name', 'split');
            expect($split)->toHaveKey('minimum');
            expect($split)->toHaveKey('maximum');
            expect($split['minimum'])->toBe(0);
            expect($split['maximum'])->toBe(1);
        });
    });

    describe('all command schemas', function () {
        it('returns schemas for all alphaforge commands', function () {
            $data = runSchemaCommand();

            expect($data['success'])->toBeTrue();
            expect($data['data'])->toBeArray();
            expect($data['data'])->not->toBeEmpty();

            // Should include key commands
            expect($data['data'])->toHaveKey('alphaforge:data:import');
            expect($data['data'])->toHaveKey('alphaforge:walk-forward');
            expect($data['data'])->toHaveKey('alphaforge:strategies:list');
            expect($data['data'])->toHaveKey('alphaforge:optimize');
            expect($data['data'])->toHaveKey('alphaforge:backtest:run');
            expect($data['data'])->toHaveKey('alphaforge:monte-carlo');

            // Should NOT include schema itself
            expect($data['data'])->not->toHaveKey('alphaforge:schema');

            // Each has schema and command keys
            $first = array_values($data['data'])[0];
            expect($first)->toHaveKeys(['schema', 'command', 'description', 'arguments', 'options']);
            expect($first['schema'])->toBe('alphaforge.help.v1');
        });
    });

    describe('error handling', function () {
        it('returns error for nonexistent command', function () {
            $data = runSchemaCommand('nonexistent:command');

            expect($data['success'])->toBeFalse();
            expect($data['error'])->toContain('not found');
        });
    });

    describe('variable option types', function () {
        it('marks options that accept values vs boolean flags', function () {
            $data = runSchemaCommand('alphaforge:walk-forward');
            $opts = $data['data']['options'];

            // --exchange accepts a value
            $exchange = collect($opts)->firstWhere('name', 'exchange');
            expect($exchange)->toHaveKey('accept_value');
            expect($exchange['accept_value'])->toBeTrue();
            expect($exchange)->toHaveKey('default');

            // --force is boolean (no accept_value key)
            $force = collect($opts)->firstWhere('name', 'force');
            // Boolean options should not have accept_value=true
            expect(isset($force['accept_value']) && $force['accept_value'] === true)->toBeFalse();
        });

        it('includes is_array for array arguments', function () {
            $data = runSchemaCommand('alphaforge:backtest:run');
            $args = $data['data']['arguments'];

            $symbols = collect($args)->firstWhere('name', 'symbols');
            expect($symbols)->toHaveKey('is_array');
            expect($symbols['is_array'])->toBeTrue();
        });
    });

    describe('parameter type inference', function () {
        it('infers integer type for iterations', function () {
            $data = runSchemaCommand('alphaforge:walk-forward');
            $opts = $data['data']['options'];

            $iter = collect($opts)->firstWhere('name', 'iterations');
            expect($iter['type'])->toBe('integer');
            expect($iter['default'])->toBe('500');
            expect($iter)->toHaveKey('examples');
        });

        it('infers float type for risk-per-trade', function () {
            $data = runSchemaCommand('alphaforge:walk-forward');
            $opts = $data['data']['options'];

            $risk = collect($opts)->firstWhere('name', 'risk-per-trade');
            expect($risk['type'])->toBe('float');
            expect($risk)->toHaveKey('minimum');
            expect($risk)->toHaveKey('maximum');
        });

        it('infers allowed_values for sizing-model', function () {
            $data = runSchemaCommand('alphaforge:backtest:run');
            $opts = $data['data']['options'];

            $sizing = collect($opts)->firstWhere('name', 'sizing-model');
            expect($sizing['allowed_values'])->toContain('risk_based');
            expect($sizing['allowed_values'])->toContain('percent_of_equity');
            expect($sizing['allowed_values'])->toContain('fixed_dollar');
        });

        it('infers allowed_values for data-type', function () {
            $data = runSchemaCommand('alphaforge:backtest:run');
            $opts = $data['data']['options'];

            $dt = collect($opts)->firstWhere('name', 'data-type');
            expect($dt['allowed_values'])->toBe(['ohlcv', 'heikenashi', 'renko', 'atr_renko']);
        });
    });
});
