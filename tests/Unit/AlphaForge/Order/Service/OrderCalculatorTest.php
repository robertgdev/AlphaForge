<?php

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Order\Service\OrderCalculator;

describe('OrderCalculator', function () {
    describe('stopLoss', function () {
        it('calculates percentage-based stop loss correctly', function () {
            $result = OrderCalculator::stopLoss('100', 3.0);

            expect($result)->toBe('97.000000');
        });

        it('handles small percentages', function () {
            $result = OrderCalculator::stopLoss('100', 0.5);

            expect($result)->toBe('99.500000');
        });

        it('calculates from non-round entry price', function () {
            $result = OrderCalculator::stopLoss('4376.25', 2.5);

            expect($result)->toBe('4266.843750');
        });

        it('handles zero stop loss', function () {
            $result = OrderCalculator::stopLoss('100', 0.0);

            expect($result)->toBe('100.000000');
        });
    });

    describe('atrStopLoss', function () {
        it('calculates ATR-based stop loss', function () {
            $result = OrderCalculator::atrStopLoss('100', 2.0, 1.5);

            expect($result)->toBe('97.000000');
        });

        it('handles larger ATR values', function () {
            $result = OrderCalculator::atrStopLoss('4376.25', 85.5, 2.0);

            expect($result)->toBe('4205.250000');
        });

        it('handles zero multiplier', function () {
            $result = OrderCalculator::atrStopLoss('100', 5.0, 0.0);

            expect($result)->toBe('100.000000');
        });
    });

    describe('takeProfit', function () {
        it('calculates percentage-based take profit correctly', function () {
            $result = OrderCalculator::takeProfit('100', 6.0);

            expect($result)->toBe('106.000000');
        });

        it('handles large percentages', function () {
            $result = OrderCalculator::takeProfit('100', 50.0);

            expect($result)->toBe('150.000000');
        });

        it('calculates from non-round entry price', function () {
            $result = OrderCalculator::takeProfit('4376.25', 12.5);

            expect($result)->toBe('4923.281250');
        });

        it('handles zero take profit', function () {
            $result = OrderCalculator::takeProfit('100', 0.0);

            expect($result)->toBe('100.000000');
        });
    });

    describe('positionSize', function () {
        it('calculates 1% of capital by default', function () {
            $result = OrderCalculator::positionSize(10000.0, 1.0);

            expect($result)->toBe('100');
        });

        it('calculates fractional position size', function () {
            $result = OrderCalculator::positionSize(10000.0, 2.5);

            expect($result)->toBe('250');
        });
    });

    describe('entryOrder', function () {
        it('builds a LONG market order with SL and TP', function () {
            $signal = OrderCalculator::entryOrder(
                symbol: 'BTC/USDT',
                positionSize: '100',
                stopLoss: '97.000000',
                takeProfit: '106.000000',
            );

            expect($signal->symbol)->toBe('BTC/USDT')
                ->and($signal->direction)->toBe(DirectionEnum::LONG)
                ->and($signal->orderType)->toBe(OrderTypeEnum::Market)
                ->and($signal->stakeAmount)->toBe('100')
                ->and($signal->stopLoss)->toBe('97.000000')
                ->and($signal->takeProfit)->toBe('106.000000')
                ->and($signal->quantity)->toBeNull()
                ->and($signal->exitTags)->toBeNull()
                ->and($signal->enterTags)->toBeNull();
        });

        it('includes enter tags when provided', function () {
            $signal = OrderCalculator::entryOrder(
                symbol: 'ETH/USDT',
                positionSize: '50',
                stopLoss: '1900',
                takeProfit: '2100',
                enterTags: ['sma_cross'],
            );

            expect($signal->enterTags)->toBe(['sma_cross']);
        });

        it('sets enterTags to null when empty array provided', function () {
            $signal = OrderCalculator::entryOrder(
                symbol: 'ETH/USDT',
                positionSize: '50',
                stopLoss: '1900',
                takeProfit: '2100',
                enterTags: [],
            );

            expect($signal->enterTags)->toBeNull();
        });
    });

    describe('exitOrder', function () {
        it('builds a SHORT market order for position exit', function () {
            $signal = OrderCalculator::exitOrder(
                symbol: 'BTC/USDT',
                quantity: '0.001',
            );

            expect($signal->symbol)->toBe('BTC/USDT')
                ->and($signal->direction)->toBe(DirectionEnum::SHORT)
                ->and($signal->orderType)->toBe(OrderTypeEnum::Market)
                ->and($signal->quantity)->toBe('0.001')
                ->and($signal->stakeAmount)->toBeNull()
                ->and($signal->stopLoss)->toBeNull()
                ->and($signal->takeProfit)->toBeNull()
                ->and($signal->exitTags)->toBe(['strategy_signal']);
        });

        it('accepts custom exit tags', function () {
            $signal = OrderCalculator::exitOrder(
                symbol: 'ETH/USDT',
                quantity: '0.5',
                exitTags: ['trailing_stop', 'atr_exit'],
            );

            expect($signal->exitTags)->toBe(['trailing_stop', 'atr_exit']);
        });
    });
});
