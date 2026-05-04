<?php

namespace App\AlphaForge\Order\Model\Pricing;

final class PercentageCommission implements CommissionInterface
{
    public function __construct(
        private string $rate
    ) {}

    public function calculate(string $quantity, string $price): string
    {
        $tradeValue = bcmul($quantity, $price);

        return bcmul($tradeValue, $this->rate);
    }
}
