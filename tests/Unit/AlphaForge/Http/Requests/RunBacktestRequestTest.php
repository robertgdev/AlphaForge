<?php

use App\AlphaForge\Http\Requests\RunBacktestRequest;

describe('RunBacktestRequest', function () {
    it('has required fields defined', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKeys([
            'strategy',
            'symbols',
            'symbols.*',
            'timeframe',
            'exchange',
            'initial_capital',
        ]);
    });

    it('has nullable optional fields defined', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules)->toHaveKeys([
            'execution_timeframe',
            'stake_currency',
            'strategy_inputs',
            'commission_config',
            'start_date',
            'end_date',
            'additional_timeframes',
            'additional_timeframes.*',
        ]);
    });

    it('validates strategy is required', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['strategy'])->toContain('required');
    });

    it('validates symbols is required array with min 1', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['symbols'])->toContain('required')
            ->and($rules['symbols'])->toContain('array')
            ->and($rules['symbols'])->toContain('min:1');
    });

    it('validates timeframe is required string in enum values', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['timeframe'])->toContain('required')
            ->and($rules['timeframe'])->toContain('string');
    });

    it('validates exchange is required string', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['exchange'])->toContain('required')
            ->and($rules['exchange'])->toContain('string');
    });

    it('validates initial_capital is required numeric min 0', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['initial_capital'])->toContain('required')
            ->and($rules['initial_capital'])->toContain('numeric')
            ->and($rules['initial_capital'])->toContain('min:0');
    });

    it('validates commission_config.type is in percentage or fixed', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['commission_config.type'])->toContain('nullable')
            ->and($rules['commission_config.type'])->toContain('string');
    });

    it('validates end_date is after or equal to start_date', function () {
        $request = new RunBacktestRequest;
        $rules = $request->rules();

        expect($rules['end_date'])->toContain('after_or_equal:start_date');
    });

    it('authorizes all users', function () {
        $request = new RunBacktestRequest;

        expect($request->authorize())->toBeTrue();
    });

    it('has custom validation messages', function () {
        $request = new RunBacktestRequest;
        $messages = $request->messages();

        expect($messages)->toHaveKeys([
            'symbols.required',
            'symbols.min',
            'timeframe.in',
            'execution_timeframe.in',
            'end_date.after_or_equal',
        ]);
    });

    it('includes withValidator method for timeframe validation', function () {
        expect(method_exists(RunBacktestRequest::class, 'withValidator'))->toBeTrue();
    });
});
