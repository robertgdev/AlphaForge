<?php

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Enum\OrderTypeEnum;

describe('OrderSignal', function () {
    it('can be created with required parameters', function () {
        $signal = new OrderSignal(
            symbol: 'BTC/USDT',
            direction: DirectionEnum::LONG,
            orderType: OrderTypeEnum::Market
        );

        expect($signal->symbol)->toBe('BTC/USDT')
            ->and($signal->direction)->toBe(DirectionEnum::LONG)
            ->and($signal->orderType)->toBe(OrderTypeEnum::Market)
            ->and($signal->stakeAmount)->toBeNull()
            ->and($signal->quantity)->toBeNull()
            ->and($signal->limitPrice)->toBeNull()
            ->and($signal->stopPrice)->toBeNull()
            ->and($signal->stopLoss)->toBeNull()
            ->and($signal->takeProfit)->toBeNull()
            ->and($signal->timeInForce)->toBeNull()
            ->and($signal->clientOrderId)->toBeNull();
    });

    it('can be created with all parameters', function () {
        $signal = new OrderSignal(
            symbol: 'BTC/USDT',
            direction: DirectionEnum::LONG,
            orderType: OrderTypeEnum::Limit,
            stakeAmount: '1000',
            quantity: '0.02',
            limitPrice: '49000',
            stopPrice: null,
            stopLoss: '45000',
            takeProfit: '55000',
            timeInForce: 12,
            clientOrderId: 'client_123'
        );

        expect($signal->stakeAmount)->toBe('1000')
            ->and($signal->quantity)->toBe('0.02')
            ->and($signal->limitPrice)->toBe('49000')
            ->and($signal->stopLoss)->toBe('45000')
            ->and($signal->takeProfit)->toBe('55000')
            ->and($signal->timeInForce)->toBe(12)
            ->and($signal->clientOrderId)->toBe('client_123');
    });

    it('is readonly', function () {
        $signal = new OrderSignal(
            symbol: 'BTC/USDT',
            direction: DirectionEnum::SHORT,
            orderType: OrderTypeEnum::Stop
        );

        expect($signal)->toBeInstanceOf(OrderSignal::class);
    });
});
