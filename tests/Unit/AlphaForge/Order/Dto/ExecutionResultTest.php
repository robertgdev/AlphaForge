<?php

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Dto\ExecutionResult;
use App\AlphaForge\Order\Dto\PositionDto;
use Carbon\Carbon;

describe('ExecutionResult', function () {
    it('can be created with all parameters', function () {
        $position = new PositionDto(
            id: 'pos_1',
            symbol: 'BTC/USDT',
            direction: 'long',
            quantity: '0.02',
            entryPrice: '50000',
            entryTime: Carbon::now()
        );

        $result = new ExecutionResult(
            orderId: 'order_1',
            symbol: 'BTC/USDT',
            direction: DirectionEnum::LONG,
            quantity: '0.02',
            price: '50000',
            commission: '1.000000000000',
            timestamp: Carbon::now(),
            position: $position
        );

        expect($result->orderId)->toBe('order_1')
            ->and($result->symbol)->toBe('BTC/USDT')
            ->and($result->direction)->toBe(DirectionEnum::LONG)
            ->and($result->quantity)->toBe('0.02')
            ->and($result->price)->toBe('50000')
            ->and($result->commission)->toBe('1.000000000000')
            ->and($result->position)->not->toBeNull()
            ->and($result->position->id)->toBe('pos_1');
    });

    it('position defaults to null', function () {
        $result = new ExecutionResult(
            orderId: 'order_1',
            symbol: 'BTC/USDT',
            direction: DirectionEnum::SHORT,
            quantity: '0.01',
            price: '55000',
            commission: '0.50',
            timestamp: Carbon::now()
        );

        expect($result->position)->toBeNull();
    });

    it('is readonly', function () {
        $result = new ExecutionResult(
            orderId: 'order_1',
            symbol: 'BTC/USDT',
            direction: DirectionEnum::LONG,
            quantity: '0.02',
            price: '50000',
            commission: '1.00',
            timestamp: Carbon::now()
        );

        expect($result)->toBeInstanceOf(ExecutionResult::class);
    });
});
