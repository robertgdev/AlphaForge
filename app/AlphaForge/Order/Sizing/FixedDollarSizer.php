<?php

namespace App\AlphaForge\Order\Sizing;

use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Model\PortfolioManager;

/**
 * Fixed dollar amount per trade regardless of account size.
 *
 * Config keys:
 *   - fixedStake (string): Dollar amount per trade (default "100")
 */
class FixedDollarSizer implements PositionSizer
{
    public function calculate(OrderSignal $signal, PortfolioManager $portfolio, array $config, string $entryPrice): string
    {
        return (string) ($config['fixedStake'] ?? '100');
    }
}
