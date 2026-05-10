<?php

use App\AlphaForge\ExitRule\ExitContext;
use App\AlphaForge\ExitRule\ExitTrigger;
use App\AlphaForge\Order\Dto\PositionDto;
use Carbon\Carbon;

function makePosition(
    string $direction = 'long',
    string $entryPrice = '100',
    ?string $stopLoss = null,
    ?string $takeProfit = null,
): PositionDto {
    return new PositionDto(
        id: 'pos_test',
        symbol: 'BTC/USDT',
        direction: $direction,
        quantity: '1',
        entryPrice: $entryPrice,
        entryTime: Carbon::now(),
        realizedPnl: '0',
        stopLoss: $stopLoss,
        takeProfit: $takeProfit,
    );
}

function makeContext(
    ?PositionDto $position = null,
    float $open = 100.0,
    float $high = 105.0,
    float $low = 95.0,
    float $close = 100.0,
    int $barIndex = 0,
    int $barsInPosition = 5,
    float $highestSinceEntry = 110.0,
    float $lowestSinceEntry = 90.0,
): ExitContext {
    return new ExitContext(
        position: $position ?? makePosition(),
        barIndex: $barIndex,
        open: $open,
        high: $high,
        low: $low,
        close: $close,
        volume: 1000.0,
        timestamp: 1700000000,
        barsInPosition: $barsInPosition,
        highestSinceEntry: $highestSinceEntry,
        lowestSinceEntry: $lowestSinceEntry,
    );
}

describe('ExitContext', function () {
    it('is a readonly value object', function () {
        $ctx = makeContext();
        expect($ctx)->toBeInstanceOf(ExitContext::class)
            ->and($ctx->high)->toBe(105.0)
            ->and($ctx->low)->toBe(95.0)
            ->and($ctx->close)->toBe(100.0)
            ->and($ctx->barsInPosition)->toBe(5)
            ->and($ctx->highestSinceEntry)->toBe(110.0)
            ->and($ctx->lowestSinceEntry)->toBe(90.0);
    });
});

describe('ExitTrigger', function () {
    it('holds rule ID, exit price, and optional tag', function () {
        $trigger = new ExitTrigger('stop_loss', 95.0);
        expect($trigger->ruleId)->toBe('stop_loss')
            ->and($trigger->exitPrice)->toBe(95.0)
            ->and($trigger->exitTag)->toBeNull();
    });

    it('accepts an exit tag', function () {
        $trigger = new ExitTrigger('condition_exit', 100.0, 'sma_cross');
        expect($trigger->exitTag)->toBe('sma_cross');
    });
});
