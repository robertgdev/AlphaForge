<?php

namespace App\AlphaForge\Order\Dto;

use Carbon\Carbon;

final readonly class PositionDto
{
    /**
     * @param  string  $id  Unique identifier for this position
     * @param  string  $symbol  Trading symbol
     * @param  string  $direction  'long' or 'short'
     * @param  string  $quantity  Position quantity
     * @param  string  $entryPrice  Entry price
     * @param  Carbon  $entryTime  Entry timestamp
     * @param  string|null  $exitPrice  Exit price (if closed)
     * @param  Carbon|null  $exitTime  Exit timestamp (if closed)
     * @param  string  $realizedPnl  Realized profit/loss (if closed)
     * @param  string|null  $stopLoss  Stop loss price
     * @param  string|null  $takeProfit  Take profit price
     * @param  string  $costBasis  Original cost basis
     * @param  string  $commission  Total commission paid
     */
    public function __construct(
        public string $id,
        public string $symbol,
        public string $direction,
        public string $quantity,
        public string $entryPrice,
        public Carbon $entryTime,
        public string $realizedPnl,
        public ?string $exitPrice = null,
        public ?Carbon $exitTime = null,
        public ?string $stopLoss = null,
        public ?string $takeProfit = null,
        public string $costBasis = '0',
        public string $commission = '0',
        public ?string $exitTag = null,
    ) {}
}
