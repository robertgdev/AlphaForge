<?php

use App\AlphaForge\Order\Dto\PositionDto;
use Carbon\Carbon;

describe('PositionDto', function () {
    it('can be created with required parameters', function () {
        $entryTime = Carbon::now();

        $position = new PositionDto(
            id: 'pos_1',
            symbol: 'BTC/USDT',
            direction: 'long',
            quantity: '0.02',
            entryPrice: '50000',
            entryTime: $entryTime
        );

        expect($position->id)->toBe('pos_1')
            ->and($position->symbol)->toBe('BTC/USDT')
            ->and($position->direction)->toBe('long')
            ->and($position->quantity)->toBe('0.02')
            ->and($position->entryPrice)->toBe('50000')
            ->and($position->entryTime)->toBe($entryTime)
            ->and($position->exitPrice)->toBeNull()
            ->and($position->exitTime)->toBeNull()
            ->and($position->realizedPnl)->toBe('0')
            ->and($position->stopLoss)->toBeNull()
            ->and($position->takeProfit)->toBeNull()
            ->and($position->costBasis)->toBe('0')
            ->and($position->commission)->toBe('0');
    });

    it('can be created with all parameters', function () {
        $entryTime = Carbon::now()->subDay();
        $exitTime = Carbon::now();

        $position = new PositionDto(
            id: 'pos_2',
            symbol: 'ETH/USDT',
            direction: 'short',
            quantity: '5.0',
            entryPrice: '3000',
            entryTime: $entryTime,
            exitPrice: '2900',
            exitTime: $exitTime,
            realizedPnl: '500.000000000000',
            stopLoss: '3200',
            takeProfit: '2800',
            costBasis: '15000',
            commission: '30.000000000000'
        );

        expect($position->exitPrice)->toBe('2900')
            ->and($position->exitTime)->toBe($exitTime)
            ->and($position->realizedPnl)->toBe('500.000000000000')
            ->and($position->stopLoss)->toBe('3200')
            ->and($position->takeProfit)->toBe('2800')
            ->and($position->costBasis)->toBe('15000')
            ->and($position->commission)->toBe('30.000000000000');
    });

    it('is readonly', function () {
        $position = new PositionDto(
            id: 'pos_1',
            symbol: 'BTC/USDT',
            direction: 'long',
            quantity: '0.02',
            entryPrice: '50000',
            entryTime: Carbon::now()
        );

        expect($position)->toBeInstanceOf(PositionDto::class);
    });
});
