<?php

namespace App\AlphaForge\Order\Dto;

use App\AlphaForge\Common\Enum\DirectionEnum;
use Carbon\Carbon;

final readonly class ExecutionResult
{
    /**
     * @param  string  $orderId  System-generated unique ID for this execution
     * @param  string  $symbol  Trading symbol
     * @param  DirectionEnum  $direction  Trade direction
     * @param  string  $quantity  Filled quantity
     * @param  string  $price  Execution price
     * @param  string  $commission  Commission amount
     * @param  Carbon  $timestamp  Execution timestamp
     * @param  PositionDto|null  $position  Resulting position (for entries)
     * @param  string|null  $clientOrderId  Client-provided order ID
     * @param  float|null  $stopLossPrice  Stop loss price
     * @param  float|null  $takeProfitPrice  Take profit price
     * @param  array<string>|null  $enterTags  Entry tags
     * @param  array<string>|null  $exitTags  Exit tags
     */
    public function __construct(
        public string $orderId,
        public string $symbol,
        public DirectionEnum $direction,
        public string $quantity,
        public string $price,
        public string $commission,
        public Carbon $timestamp,
        public ?PositionDto $position = null,
        public ?string $clientOrderId = null,
        public ?float $stopLossPrice = null,
        public ?float $takeProfitPrice = null,
        public ?array $enterTags = null,
        public ?array $exitTags = null,
    ) {}
}
