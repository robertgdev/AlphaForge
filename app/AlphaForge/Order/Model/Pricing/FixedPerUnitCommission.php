<?php

namespace App\AlphaForge\Order\Model\Pricing;

final class FixedPerUnitCommission implements CommissionInterface
{
    public function __construct(
        private string $rate
    ) {}

    public function calculate(string $quantity, string $price): string
    {
        return bcmul($quantity, $this->rate);
    }
}
