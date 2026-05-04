<?php

use App\Stochastix\Common\Enum\DirectionEnum;
use App\Stochastix\Order\Dto\PendingOrder;
use App\Stochastix\Order\Enum\OrderTypeEnum;
use App\Stochastix\Order\Model\OrderManager;
use Carbon\Carbon;

describe('OrderManager', function () {
    beforeEach(function () {
        $this->orderManager = new OrderManager;
    });

    it('starts with no pending orders', function () {
        expect($this->orderManager->hasPendingOrders())->toBeFalse();
    });

    it('can add a pending order', function () {
        $order = new PendingOrder(
            id: 'order_1',
            symbol: 'BTCUSDT',
            direction: DirectionEnum::LONG,
            type: OrderTypeEnum::Market,
            stakeAmount: '1000',
            createdAt: Carbon::now(),
            price: null,
            stopPrice: null,
            stopLoss: '45000',
            takeProfit: '50000'
        );

        $this->orderManager->addPendingOrder($order);

        expect($this->orderManager->hasPendingOrders())->toBeTrue();
    });

    it('can get pending orders', function () {
        $order1 = new PendingOrder(
            id: 'order_1',
            symbol: 'BTCUSDT',
            direction: DirectionEnum::LONG,
            type: OrderTypeEnum::Market,
            stakeAmount: '1000',
            createdAt: Carbon::now()
        );

        $order2 = new PendingOrder(
            id: 'order_2',
            symbol: 'ETHUSDT',
            direction: DirectionEnum::SHORT,
            type: OrderTypeEnum::Limit,
            stakeAmount: '500',
            createdAt: Carbon::now(),
            price: '3000'
        );

        $this->orderManager->addPendingOrder($order1);
        $this->orderManager->addPendingOrder($order2);

        $pendingOrders = $this->orderManager->getPendingOrders();
        expect(iterator_to_array($pendingOrders))->toHaveCount(2);
    });

    it('can remove a pending order', function () {
        $order = new PendingOrder(
            id: 'order_1',
            symbol: 'BTCUSDT',
            direction: DirectionEnum::LONG,
            type: OrderTypeEnum::Market,
            stakeAmount: '1000',
            createdAt: Carbon::now()
        );

        $this->orderManager->addPendingOrder($order);
        $this->orderManager->removePendingOrder('order_1');

        expect($this->orderManager->hasPendingOrders())->toBeFalse();
    });

    it('can get a specific pending order by id', function () {
        $order = new PendingOrder(
            id: 'order_1',
            symbol: 'BTCUSDT',
            direction: DirectionEnum::LONG,
            type: OrderTypeEnum::Market,
            stakeAmount: '1000',
            createdAt: Carbon::now()
        );

        $this->orderManager->addPendingOrder($order);

        $retrieved = $this->orderManager->getPendingOrder('order_1');
        expect($retrieved)->not->toBeNull();
        expect($retrieved->symbol)->toBe('BTCUSDT');
    });

    it('returns null for non-existent order', function () {
        $retrieved = $this->orderManager->getPendingOrder('non_existent');
        expect($retrieved)->toBeNull();
    });

    it('can clear all pending orders', function () {
        $order1 = new PendingOrder(
            id: 'order_1',
            symbol: 'BTCUSDT',
            direction: DirectionEnum::LONG,
            type: OrderTypeEnum::Market,
            stakeAmount: '1000',
            createdAt: Carbon::now()
        );

        $order2 = new PendingOrder(
            id: 'order_2',
            symbol: 'ETHUSDT',
            direction: DirectionEnum::SHORT,
            type: OrderTypeEnum::Market,
            stakeAmount: '500',
            createdAt: Carbon::now()
        );

        $this->orderManager->addPendingOrder($order1);
        $this->orderManager->addPendingOrder($order2);
        $this->orderManager->clearPendingOrders();

        expect($this->orderManager->hasPendingOrders())->toBeFalse();
    });
});
