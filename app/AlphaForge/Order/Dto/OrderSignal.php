<?php

namespace App\AlphaForge\Order\Dto;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Enum\OrderTypeEnum;

final readonly class OrderSignal
{
    /**
     * @param  string  $symbol  Trading symbol
     * @param  DirectionEnum  $direction  Trade direction (Long/Short)
     * @param  OrderTypeEnum  $orderType  Order type (Market/Limit/Stop)
     * @param  string|null  $stakeAmount  Amount to stake (in quote currency)
     * @param  string|null  $quantity  Fixed quantity (overrides stakeAmount)
     * @param  string|null  $limitPrice  Limit price for LIMIT orders
     * @param  string|null  $stopPrice  Stop trigger price for STOP orders
     * @param  string|null  $stopLoss  Stop loss price for risk management
     * @param  string|null  $takeProfit  Take profit price for risk management
     * @param  int|null  $timeInForce  Bars until order expires (null = GTC)
     * @param  string|null  $clientOrderId  Client-provided order ID for tracking
     */
    public function __construct(
        public string $symbol,
        public DirectionEnum $direction,
        public OrderTypeEnum $orderType,
        public ?string $stakeAmount = null,
        public ?string $quantity = null,
        public ?string $limitPrice = null,
        public ?string $stopPrice = null,
        public ?string $stopLoss = null,
        public ?string $takeProfit = null,
        public ?int $timeInForce = null,
        public ?string $clientOrderId = null
    ) {}
}
