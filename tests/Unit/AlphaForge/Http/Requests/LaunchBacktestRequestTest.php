<?php

use App\AlphaForge\Http\Requests\LaunchBacktestRequest;

describe('LaunchBacktestRequest', function () {
    it('has required fields defined', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKeys([
            'strategy_alias',
            'symbols',
            'symbols.*',
            'timeframe',
        ]);
    });

    it('has nullable optional fields defined', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKeys([
            'execution_timeframe',
            'exchange',
            'initial_capital',
            'stake_currency',
            'inputs',
            'commission',
            'start_date',
            'end_date',
        ]);
    });

    it('validates strategy_alias is required string', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['strategy_alias'])->toContain('required')
            ->and($rules['strategy_alias'])->toContain('string');
    });

    it('validates symbols is required array with min 1', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['symbols'])->toContain('required')
            ->and($rules['symbols'])->toContain('array')
            ->and($rules['symbols'])->toContain('min:1');
    });

    it('validates symbols.* is required string', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['symbols.*'])->toContain('required')
            ->and($rules['symbols.*'])->toContain('string');
    });

    it('validates timeframe is required string in enum values', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['timeframe'])->toContain('required')
            ->and($rules['timeframe'])->toContain('string');
    });

    it('validates initial_capital is nullable numeric min 0', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['initial_capital'])->toContain('nullable')
            ->and($rules['initial_capital'])->toContain('numeric')
            ->and($rules['initial_capital'])->toContain('min:0');
    });

    it('validates stake_currency is nullable string size 3', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['stake_currency'])->toContain('nullable')
            ->and($rules['stake_currency'])->toContain('string')
            ->and($rules['stake_currency'])->toContain('size:3');
    });

    it('validates commission.type is in percentage,fixed_per_trade,fixed_per_unit', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['commission.type'])->toContain('nullable')
            ->and($rules['commission.type'])->toContain('string');
    });

    it('validates end_date is after or equal to start_date', function () {
        $request = new LaunchBacktestRequest;
        $rules = $request->rules();

        expect($rules['end_date'])->toContain('after_or_equal:start_date');
    });

    it('has custom validation messages', function () {
        $request = new LaunchBacktestRequest;
        $messages = $request->messages();

        expect($messages)->toHaveKeys([
            'strategy_alias.required',
            'symbols.required',
            'symbols.min',
            'timeframe.required',
            'timeframe.in',
            'execution_timeframe.in',
            'end_date.after_or_equal',
        ]);
    });

    it('includes withValidator method for timeframe validation', function () {
        expect(method_exists(LaunchBacktestRequest::class, 'withValidator'))->toBeTrue();
    });
});
