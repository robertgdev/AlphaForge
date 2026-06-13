<?php

namespace App\AlphaForge\Order\Sizing;

use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Model\PortfolioManager;

/**
 * Allocates a fixed percentage of total equity per trade.
 *
 * Default: 1% of equity. Replicates current behavior.
 *
 * Config keys:
 *   - percent (float): Percentage of equity to allocate per trade (default 1.0)
 */
class PercentOfEquitySizer implements PositionSizer
{
    public function calculate(OrderSignal $signal, PortfolioManager $portfolio, array $config, string $entryPrice): string
    {
        $percent = (float) ($config['percent'] ?? 1.0);
        $equity = $portfolio->getTotalEquity();

        return bcdiv(bcmul($equity, (string) $percent, 12), '100', 12);
    }
}
