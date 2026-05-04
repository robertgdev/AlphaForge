<?php

namespace App\AlphaForge\Order\Model\Pricing;

interface CommissionInterface
{
    /**
     * Calculate the commission for a trade.
     *
     * @param  string  $quantity  The quantity as a string
     * @param  string  $price  The price as a string
     * @return string The commission amount as a string
     */
    public function calculate(string $quantity, string $price): string;
}
