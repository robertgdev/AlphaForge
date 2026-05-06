<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Indicator\Model\IndicatorManagerInterface;
use App\AlphaForge\Order\Model\OrderManagerInterface;
use App\AlphaForge\Strategy\Model\StrategyContext;
use Ds\Map;

describe('StrategyContext', function () {
    it('returns indicator manager', function () {
        $indicators = Mockery::mock(IndicatorManagerInterface::class);
        $orders = Mockery::mock(OrderManagerInterface::class);
        $cursor = new BacktestCursor;
        $dataframes = new Map;

        $context = new StrategyContext($indicators, $orders, $cursor, $dataframes);

        expect($context->getIndicators())->toBe($indicators);
    });

    it('returns order manager', function () {
        $indicators = Mockery::mock(IndicatorManagerInterface::class);
        $orders = Mockery::mock(OrderManagerInterface::class);
        $cursor = new BacktestCursor;
        $dataframes = new Map;

        $context = new StrategyContext($indicators, $orders, $cursor, $dataframes);

        expect($context->getOrders())->toBe($orders);
    });

    it('returns current bar index from cursor', function () {
        $indicators = Mockery::mock(IndicatorManagerInterface::class);
        $orders = Mockery::mock(OrderManagerInterface::class);
        $cursor = new BacktestCursor;
        $cursor->currentIndex = 42;
        $dataframes = new Map;

        $context = new StrategyContext($indicators, $orders, $cursor, $dataframes);

        expect($context->getCurrentBarIndex())->toBe(42);
    });

    it('returns dataframes map', function () {
        $indicators = Mockery::mock(IndicatorManagerInterface::class);
        $orders = Mockery::mock(OrderManagerInterface::class);
        $cursor = new BacktestCursor;
        $dataframes = new Map(['key' => 'value']);

        $context = new StrategyContext($indicators, $orders, $cursor, $dataframes);

        expect($context->getDataframes())->toBe($dataframes)
            ->and($context->getDataframes()->get('key'))->toBe('value');
    });

    it('returns null current symbol by default', function () {
        $indicators = Mockery::mock(IndicatorManagerInterface::class);
        $orders = Mockery::mock(OrderManagerInterface::class);
        $cursor = new BacktestCursor;
        $dataframes = new Map;

        $context = new StrategyContext($indicators, $orders, $cursor, $dataframes);

        expect($context->getCurrentSymbol())->toBeNull();
    });

    it('allows setting current symbol', function () {
        $indicators = Mockery::mock(IndicatorManagerInterface::class);
        $orders = Mockery::mock(OrderManagerInterface::class);
        $cursor = new BacktestCursor;
        $dataframes = new Map;

        $context = new StrategyContext($indicators, $orders, $cursor, $dataframes);
        $context->currentSymbol = 'BTC/USDT';

        expect($context->getCurrentSymbol())->toBe('BTC/USDT');
    });
});
