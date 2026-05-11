<?php

use App\AlphaForge\Backtesting\Model\WalkForwardRun;

describe('WalkForwardRun model', function () {
    it('has execution_timeframe in fillable', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['fillable'])->toContain('execution_timeframe');
    });

    it('has min_trades_threshold in fillable', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['fillable'])->toContain('min_trades_threshold');
    });

    it('has min_trades_threshold cast to integer', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['casts'])->toHaveKey('min_trades_threshold')
            ->and($defaultProps['casts']['min_trades_threshold'])->toBe('integer');
    });

    it('has datetime casts for date fields', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['casts'])->toHaveKey('is_start_date')
            ->and($defaultProps['casts'])->toHaveKey('is_end_date')
            ->and($defaultProps['casts'])->toHaveKey('oos_start_date')
            ->and($defaultProps['casts'])->toHaveKey('oos_end_date')
            ->and($defaultProps['casts']['is_start_date'])->toBe('datetime')
            ->and($defaultProps['casts']['is_end_date'])->toBe('datetime')
            ->and($defaultProps['casts']['oos_start_date'])->toBe('datetime')
            ->and($defaultProps['casts']['oos_end_date'])->toBe('datetime');
    });

    it('has array casts for JSON fields', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['casts'])->toHaveKey('symbols')
            ->and($defaultProps['casts'])->toHaveKey('commission_config')
            ->and($defaultProps['casts'])->toHaveKey('parameter_ranges')
            ->and($defaultProps['casts'])->toHaveKey('best_parameters')
            ->and($defaultProps['casts']['symbols'])->toBe('array')
            ->and($defaultProps['casts']['commission_config'])->toBe('array')
            ->and($defaultProps['casts']['parameter_ranges'])->toBe('array')
            ->and($defaultProps['casts']['best_parameters'])->toBe('array');
    });

    it('uses HasUuids trait', function () {
        $traits = class_uses_recursive(WalkForwardRun::class);
        expect($traits)->toHaveKey('Illuminate\Database\Eloquent\Concerns\HasUuids');
    });

    it('has results relationship method', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        expect($ref->hasMethod('results'))->toBeTrue();
    });

    it('has optimizationRun relationship method', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        expect($ref->hasMethod('optimizationRun'))->toBeTrue();
    });

    it('has status check methods', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        expect($ref->hasMethod('isPending'))->toBeTrue()
            ->and($ref->hasMethod('isOptimizing'))->toBeTrue()
            ->and($ref->hasMethod('isForwardTesting'))->toBeTrue()
            ->and($ref->hasMethod('isCompleted'))->toBeTrue()
            ->and($ref->hasMethod('hasFailed'))->toBeTrue();
    });

    it('has state transition methods', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        expect($ref->hasMethod('markAsOptimizing'))->toBeTrue()
            ->and($ref->hasMethod('markAsForwardTesting'))->toBeTrue()
            ->and($ref->hasMethod('markAsCompleted'))->toBeTrue()
            ->and($ref->hasMethod('markAsFailed'))->toBeTrue();
    });

    it('has all expected fillable fields', function () {
        $ref = new ReflectionClass(WalkForwardRun::class);
        $defaultProps = $ref->getDefaultProperties();
        $fillable = $defaultProps['fillable'];

        $expected = [
            'user_id', 'optimization_run_id', 'strategy_alias', 'symbols',
            'timeframe', 'exchange', 'initial_capital', 'stake_currency',
            'commission_config', 'is_start_date', 'is_end_date',
            'oos_start_date', 'oos_end_date', 'split_ratio',
            'optimization_method', 'optimization_objective', 'top_n',
            'parameter_ranges', 'total_combinations', 'completed_combinations',
            'execution_timeframe', 'min_trades_threshold', 'status',
            'error_message', 'best_parameters', 'best_is_statistics',
            'best_oos_statistics', 'started_at', 'completed_at',
        ];

        foreach ($expected as $field) {
            expect($fillable)->toContain($field);
        }
    });
});
