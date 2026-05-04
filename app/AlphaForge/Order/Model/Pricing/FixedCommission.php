<?php

namespace App\AlphaForge\Order\Model\Pricing;

final class FixedCommission implements CommissionInterface
{
    public function __construct(
        private string $amount
    ) {}

    public function calculate(string $quantity, string $price): string
    {
        return $this->amount;
    }
}
