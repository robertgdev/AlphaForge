<?php

namespace App\AlphaForge\Order\Service;

use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Order\Dto\ExecutionResult;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Order\Model\Pricing\CommissionInterface;
use Carbon\Carbon;

final class OrderExecutor implements OrderExecutorInterface
{
    public function __construct(
        private CommissionInterface $commissionModel
    ) {}

    public function execute(OrderSignal $signal, OhlcvSeries $executionBarData, \DateTimeImmutable $executionTime): ?ExecutionResult
    {
        // Determine fill price based on order type
        $fillPrice = match ($signal->orderType) {
            OrderTypeEnum::Market => (string) $executionBarData->getOpen()[0], // Use open price for market orders
            OrderTypeEnum::Limit, OrderTypeEnum::Stop => $signal->price ?? (string) $executionBarData->getOpen()[0],
        };

        $commission = $this->commissionModel->calculate($signal->quantity, $fillPrice);
        $orderId = uniqid('order_', true);

        return new ExecutionResult(
            orderId: $orderId,
            symbol: $signal->symbol,
            direction: $signal->direction,
            quantity: (string) $signal->quantity,
            price: $fillPrice,
            commission: $commission,
            timestamp: Carbon::instance($executionTime),
            clientOrderId: $signal->clientOrderId,
            stopLossPrice: $signal->stopLossPrice,
            takeProfitPrice: $signal->takeProfitPrice,
            enterTags: $signal->enterTags,
            exitTags: $signal->exitTags
        );
    }
}
