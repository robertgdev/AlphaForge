<?php

namespace App\AlphaForge\Order\Dto;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use Carbon\Carbon;

final readonly class PendingOrder
{
    public function __construct(
        public string $id,
        public string $symbol,
        public DirectionEnum $direction,
        public OrderTypeEnum $type,
        public string $stakeAmount,
        public Carbon $createdAt,
        public ?string $price = null,
        public ?string $stopPrice = null,
        public ?string $stopLoss = null,
        public ?string $takeProfit = null
    ) {}
}
