<?php

namespace App\AlphaForge\Order\Sizing;

use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Model\PortfolioManager;

interface PositionSizer
{
    /**
     * Calculate the position size (in quote currency) for a trade signal.
     *
     * @param  array<string, mixed>  $config  Sizer-specific configuration
     * @param  string  $entryPrice  Current bar close price used as entry price
     * @return string  Position size as a bcmath-compatible string
     */
    public function calculate(OrderSignal $signal, PortfolioManager $portfolio, array $config, string $entryPrice): string;
}
