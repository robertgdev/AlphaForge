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
     */
    public function __construct(
        public string $orderId,
        public string $symbol,
        public DirectionEnum $direction,
        public string $quantity,
        public string $price,
        public string $commission,
        public Carbon $timestamp,
        public ?PositionDto $position = null
    ) {}
}
