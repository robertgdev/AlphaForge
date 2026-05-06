<?php

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Dto\PendingOrder;
use App\AlphaForge\Order\Model\PortfolioManager;
use Carbon\Carbon;

describe('PortfolioManager', function () {
    beforeEach(function () {
        $this->manager = new PortfolioManager('10000.000000000000');
    });

    describe('initial state', function () {
        it('returns initial capital', function () {
            expect($this->manager->getInitialCapital())->toBe('10000.000000000000');
        });

        it('returns cash balance equal to initial capital', function () {
            expect($this->manager->getCashBalance())->toBe('10000.000000000000');
        });

        it('returns available cash equal to cash balance', function () {
            expect($this->manager->getAvailableCash())->toBe('10000.000000000000');
        });

        it('has no open positions', function () {
            $positions = $this->manager->getOpenPositions();
            expect(iterator_to_array($positions))->toHaveCount(0);
        });

        it('has no closed positions', function () {
            expect($this->manager->getClosedPositions()->count())->toBe(0);
        });

        it('returns null for unknown symbol open position', function () {
            expect($this->manager->getOpenPosition('BTC/USDT'))->toBeNull();
        });

        it('returns null for unknown position ID', function () {
            expect($this->manager->getOpenPositionById('nonexistent'))->toBeNull();
        });
    });

    describe('long entry', function () {
        it('deducts stake amount plus commission from cash balance', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now(),
                stopLoss: '45000',
                takeProfit: '55000'
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now(), [
                'type' => 'percentage',
                'rate' => '0.1',
            ]);

            $expectedCommission = '1.000000000000';
            $expectedDeduction = bcadd('1000', $expectedCommission, 12);
            $expectedBalance = bcsub('10000.000000000000', $expectedDeduction, 12);

            expect($result)->not->toBeNull()
                ->and($result->position)->not->toBeNull()
                ->and($result->position->direction)->toBe('long')
                ->and($result->position->symbol)->toBe('BTC/USDT')
                ->and($result->position->entryPrice)->toBe('50000')
                ->and($result->position->stopLoss)->toBe('45000')
                ->and($result->position->takeProfit)->toBe('55000')
                ->and($result->commission)->toBe($expectedCommission)
                ->and($this->manager->getCashBalance())->toBe($expectedBalance);
        });

        it('creates an open position retrievable by symbol', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $this->manager->executeOrder($order, '50000', Carbon::now());

            $position = $this->manager->getOpenPosition('BTC/USDT');
            expect($position)->not->toBeNull()
                ->and($position->direction)->toBe('long')
                ->and($position->entryPrice)->toBe('50000');
        });

        it('creates an open position retrievable by ID', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $position = $this->manager->getOpenPositionById($result->position->id);

            expect($position)->not->toBeNull()
                ->and($position->id)->toBe($result->position->id);
        });

        it('calculates quantity from stake and price', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $expectedQty = bcdiv('1000', '50000', 12);

            expect($result->quantity)->toBe($expectedQty);
        });
    });

    describe('short entry (closing existing long)', function () {
        it('closes existing long position on short signal', function () {
            $entryOrder = new PendingOrder(
                id: 'order_entry',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $entryResult = $this->manager->executeOrder($entryOrder, '50000', Carbon::now());

            $exitOrder = new PendingOrder(
                id: 'order_exit',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::SHORT,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $exitResult = $this->manager->executeOrder($exitOrder, '55000', Carbon::now());

            expect($exitResult)->not->toBeNull()
                ->and($exitResult->position)->not->toBeNull()
                ->and($exitResult->position->exitTime)->not->toBeNull()
                ->and($exitResult->position->exitPrice)->toBe('55000');

            expect($this->manager->getOpenPosition('BTC/USDT'))->toBeNull();
            expect($this->manager->getClosedPositions()->count())->toBe(1);
        });
    });

    describe('short entry (new position)', function () {
        it('creates a new short position when no existing position', function () {
            $order = new PendingOrder(
                id: 'order_short',
                symbol: 'ETH/USDT',
                direction: DirectionEnum::SHORT,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '500',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '3000', Carbon::now());

            expect($result)->not->toBeNull()
                ->and($result->position)->not->toBeNull()
                ->and($result->position->direction)->toBe('short')
                ->and($result->position->entryPrice)->toBe('3000');

            $position = $this->manager->getOpenPosition('ETH/USDT');
            expect($position)->not->toBeNull()
                ->and($position->direction)->toBe('short');
        });
    });

    describe('closePosition', function () {
        it('returns null for non-existent position', function () {
            expect($this->manager->closePosition('nonexistent', '100', Carbon::now()))->toBeNull();
        });

        it('closes a long position with profit', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $positionId = $result->position->id;

            $closedPosition = $this->manager->closePosition($positionId, '55000', Carbon::now());

            expect($closedPosition)->not->toBeNull()
                ->and($closedPosition->exitPrice)->toBe('55000')
                ->and($closedPosition->exitTime)->not->toBeNull();

            expect(bccomp($closedPosition->realizedPnl, '0', 6))->toBeGreaterThan(0);
        });

        it('closes a long position with loss', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $positionId = $result->position->id;

            $closedPosition = $this->manager->closePosition($positionId, '45000', Carbon::now());

            expect(bccomp($closedPosition->realizedPnl, '0', 6))->toBeLessThan(0);
        });

        it('deducts exit commission from realized PnL', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now(), [
                'type' => 'percentage',
                'rate' => '0.1',
            ]);

            $closedPosition = $this->manager->closePosition(
                $result->position->id,
                '50000',
                Carbon::now(),
                ['type' => 'percentage', 'rate' => '0.1']
            );

            $totalCommission = bcadd($result->commission, $closedPosition->commission, 12);

            expect(bccomp($closedPosition->realizedPnl, '0', 6))->toBeLessThan(0);
        });

        it('adds closed position to closed positions list', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $this->manager->closePosition($result->position->id, '55000', Carbon::now());

            expect($this->manager->getClosedPositions()->count())->toBe(1);
        });

        it('removes from open positions after closing', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $this->manager->closePosition($result->position->id, '55000', Carbon::now());

            expect($this->manager->getOpenPosition('BTC/USDT'))->toBeNull();
            expect(iterator_to_array($this->manager->getOpenPositions()))->toHaveCount(0);
        });

        it('includes entry and exit commission in closed position total commission', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now(), [
                'type' => 'percentage',
                'rate' => '0.1',
            ]);

            $entryCommission = $result->commission;

            $closedPosition = $this->manager->closePosition(
                $result->position->id,
                '55000',
                Carbon::now(),
                ['type' => 'percentage', 'rate' => '0.1']
            );

            $exitValue = bcmul($closedPosition->quantity, '55000', 12);
            $expectedExitCommission = bcmul($exitValue, bcdiv('0.1', '100', 12), 12);
            $expectedTotalCommission = bcadd($entryCommission, $expectedExitCommission, 12);

            expect($closedPosition->commission)->toBe($expectedTotalCommission);
        });
    });

    describe('commission calculation', function () {
        it('calculates percentage commission', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now(), [
                'type' => 'percentage',
                'rate' => '0.1',
            ]);

            expect($result->commission)->toBe('1.000000000000');
        });

        it('calculates fixed commission', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now(), [
                'type' => 'fixed',
                'rate' => '5',
            ]);

            expect($result->commission)->toBe('5');
        });

        it('applies minimum commission when calculated is lower', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '100',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now(), [
                'type' => 'percentage',
                'rate' => '0.01',
                'minimum' => '1',
            ]);

            expect($result->commission)->toBe('1');
        });

        it('returns zero commission when no config', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());

            expect($result->commission)->toBe('0');
        });
    });

    describe('PnL calculation', function () {
        it('calculates positive PnL for profitable long', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $closedPosition = $this->manager->closePosition($result->position->id, '55000', Carbon::now());

            $quantity = bcdiv('1000', '50000', 12);
            $entryValue = bcmul($quantity, '50000', 12);
            $exitValue = bcmul($quantity, '55000', 12);
            $expectedPnl = bcsub($exitValue, $entryValue, 12);

            expect(bccomp($closedPosition->realizedPnl, $expectedPnl, 8))->toBe(0);
        });

        it('calculates negative PnL for losing long', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());
            $closedPosition = $this->manager->closePosition($result->position->id, '45000', Carbon::now());

            expect(bccomp($closedPosition->realizedPnl, '0', 6))->toBeLessThan(0);
        });
    });

    describe('default stake amount', function () {
        it('returns 10% of cash balance', function () {
            expect($this->manager->getDefaultStakeAmount())->toBe(bcdiv('10000.000000000000', '10', 12));
        });

        it('adjusts after trade', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $this->manager->executeOrder($order, '50000', Carbon::now());

            $expectedDefault = bcdiv($this->manager->getCashBalance(), '10', 12);
            expect($this->manager->getDefaultStakeAmount())->toBe($expectedDefault);
        });
    });

    describe('total equity', function () {
        it('equals cash balance when no positions', function () {
            expect($this->manager->getTotalEquity())->toBe($this->manager->getCashBalance());
        });

        it('includes open position value', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $this->manager->executeOrder($order, '50000', Carbon::now());

            $equity = $this->manager->getTotalEquity(['BTC/USDT' => '55000']);
            $quantity = bcdiv('1000', '50000', 12);
            $positionValue = bcmul($quantity, '55000', 12);
            $expected = bcadd($this->manager->getCashBalance(), $positionValue, 12);

            expect($equity)->toBe($expected);
        });

        it('uses entry price when no current price provided', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $this->manager->executeOrder($order, '50000', Carbon::now());

            $quantity = bcdiv('1000', '50000', 12);
            $positionValue = bcmul($quantity, '50000', 12);
            $expected = bcadd($this->manager->getCashBalance(), $positionValue, 12);

            expect($this->manager->getTotalEquity())->toBe($expected);
        });
    });

    describe('multiple positions', function () {
        it('can open positions on different symbols', function () {
            $order1 = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );
            $order2 = new PendingOrder(
                id: 'order_2',
                symbol: 'ETH/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '500',
                createdAt: Carbon::now()
            );

            $this->manager->executeOrder($order1, '50000', Carbon::now());
            $this->manager->executeOrder($order2, '3000', Carbon::now());

            expect($this->manager->getOpenPosition('BTC/USDT'))->not->toBeNull()
                ->and($this->manager->getOpenPosition('ETH/USDT'))->not->toBeNull();

            $openPositions = iterator_to_array($this->manager->getOpenPositions());
            expect($openPositions)->toHaveCount(2);
        });
    });

    describe('cost basis tracking', function () {
        it('stores stake amount as cost basis on long entry', function () {
            $order = new PendingOrder(
                id: 'order_1',
                symbol: 'BTC/USDT',
                direction: DirectionEnum::LONG,
                type: \App\AlphaForge\Order\Enum\OrderTypeEnum::Market,
                stakeAmount: '1000',
                createdAt: Carbon::now()
            );

            $result = $this->manager->executeOrder($order, '50000', Carbon::now());

            expect($result->position->costBasis)->toBe('1000');
        });
    });
});
