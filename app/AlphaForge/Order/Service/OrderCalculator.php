<?php

namespace App\AlphaForge\Order\Service;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Enum\OrderTypeEnum;

final class OrderCalculator
{
    /**
     * Calculate a percentage-based stop loss price from the entry price.
     *
     * Example: entry 100, 3% SL → 97
     */
    public static function stopLoss(string $entryPrice, float $percent): string
    {
        return bcmul($entryPrice, bcdiv((string) (100 - $percent), '100', 6), 6);
    }

    /**
     * Calculate an ATR-based stop loss distance from the entry price.
     */
    public static function atrStopLoss(string $entryPrice, float $atrValue, float $multiplier): string
    {
        return bcsub($entryPrice, (string) ($atrValue * $multiplier), 6);
    }

    /**
     * Calculate a percentage-based take profit price from the entry price.
     */
    public static function takeProfit(string $entryPrice, float $percent): string
    {
        return bcmul($entryPrice, bcdiv((string) (100 + $percent), '100', 6), 6);
    }

    /**
     * Calculate the position size as a string for use in OrderSignal.
     */
    public static function positionSize(float $capital, float $sizePercent): string
    {
        return (string) ($capital * $sizePercent / 100.0);
    }

    /**
     * Build a LONG entry OrderSignal.
     */
    public static function entryOrder(
        string $symbol,
        string $positionSize,
        string $stopLoss,
        string $takeProfit,
        array $enterTags = [],
    ): OrderSignal {
        return new OrderSignal(
            symbol: $symbol,
            direction: DirectionEnum::LONG,
            orderType: OrderTypeEnum::Market,
            stakeAmount: $positionSize,
            stopLoss: $stopLoss,
            takeProfit: $takeProfit,
            enterTags: $enterTags ?: null,
        );
    }

    /**
     * Build a SHORT exit OrderSignal.
     */
    public static function exitOrder(
        string $symbol,
        string $quantity,
        array $exitTags = ['strategy_signal'],
    ): OrderSignal {
        return new OrderSignal(
            symbol: $symbol,
            direction: DirectionEnum::SHORT,
            orderType: OrderTypeEnum::Market,
            quantity: $quantity,
            exitTags: $exitTags,
        );
    }
}
