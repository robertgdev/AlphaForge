<?php

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Service\Backtester;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('BacktestRunService findCompletedDuplicate', function () {
    beforeEach(function () {
        $this->service = new BacktestRunService(
            Mockery::mock(Backtester::class),
        );
    });

    it('returns null when no duplicate exists', function () {
        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('returns existing completed backtest when exact match exists', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'execution_timeframe' => null,
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'stake_currency' => 'USDT',
            'strategy_inputs' => [],
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'ohlcv',
            'brick_size' => null,
            'atr_period' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('ignores pending backtests', function () {
        BacktestRun::factory()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'status' => 'pending',
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('ignores running backtests', function () {
        BacktestRun::factory()->running()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('ignores failed backtests', function () {
        BacktestRun::factory()->failed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('differentiates by strategy alias', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'rsi_reversal',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('differentiates by symbols', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['ETHUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('matches symbols regardless of input order', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT', 'ETHUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['ETHUSDT', 'BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('matches strategy_inputs regardless of key order', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'strategy_inputs' => ['fastPeriod' => 10, 'slowPeriod' => 50],
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'strategy_inputs' => ['fastPeriod' => 10, 'slowPeriod' => 50],
            'data_type' => 'ohlcv',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('differentiates by different strategy_inputs', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'strategy_inputs' => ['fastPeriod' => 10, 'slowPeriod' => 50],
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'strategy_inputs' => ['fastPeriod' => 20, 'slowPeriod' => 100],
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('differentiates by timeframe', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '4h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('differentiates by exchange', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'kraken',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('differentiates by initial_capital', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '50000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('differentiates by data_type', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'renko',
            'brick_size' => '100',
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('matches renko backtests by brick_size', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'renko',
            'brick_size' => '100.00000000',
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'renko',
            'brick_size' => 100,
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('differentiates renko by different brick_size', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'renko',
            'brick_size' => '100',
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'renko',
            'brick_size' => 200,
        ]);

        expect($result)->toBeNull();
    });

    it('matches atr_renko by atr_period', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'atr_renko',
            'brick_size' => null,
            'atr_period' => 14,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'atr_renko',
            'atr_period' => 14,
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('differentiates atr_renko by different atr_period', function () {
        BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'atr_renko',
            'atr_period' => 14,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'atr_renko',
            'atr_period' => 20,
        ]);

        expect($result)->toBeNull();
    });

    it('treats null vs non-null execution_timeframe as different', function () {
        BacktestRun::factory()->completed()->withExecutionTimeframe('1m')->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->toBeNull();
    });

    it('matches when both have same execution_timeframe', function () {
        $existing = BacktestRun::factory()->completed()->withExecutionTimeframe('1m')->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'execution_timeframe' => '1m',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('matches when both execution_timeframes are null', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'execution_timeframe' => null,
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('matches heikenashi data type', function () {
        $existing = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
            'data_type' => 'heikenashi',
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'heikenashi',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($existing->id);
    });

    it('only checks completed duplicates when multiple statuses exist', function () {
        $completed = BacktestRun::factory()->completed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        BacktestRun::factory()->failed()->create([
            'strategy_alias' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => '10000.00000000',
            'start_date' => null,
            'end_date' => null,
        ]);

        $result = $this->service->findCompletedDuplicate([
            'strategy' => 'sma_crossover',
            'symbols' => ['BTCUSDT'],
            'timeframe' => '1h',
            'exchange' => 'binance',
            'initial_capital' => 10000,
            'data_type' => 'ohlcv',
        ]);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($completed->id);
    });
});
