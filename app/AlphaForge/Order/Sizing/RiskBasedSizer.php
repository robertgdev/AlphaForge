<?php

namespace App\AlphaForge\Order\Sizing;

use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Model\PortfolioManager;
use App\AlphaForge\Order\Service\OrderCalculator;

/**
 * Sizes positions based on risk of ruin: riskAmount / stopDistance.
 *
 *   riskAmount      = equity × riskPerTrade
 *   stopDistancePct = (entryPrice - stopLoss) / entryPrice
 *   positionSize    = riskAmount / stopDistancePct
 *   positionSize    = min(positionSize, equity × maxLeverage)
 *
 * Config keys:
 *   - riskPerTrade (float): Percentage of equity risked per trade (default 1.0)
 *   - maxLeverage (float): Maximum notional as multiple of equity (default 1.0)
 *   - defaultStopDistance (float): Percentage stop when signal has no stopLoss (default 5.0)
 */
class RiskBasedSizer implements PositionSizer
{
    public function calculate(OrderSignal $signal, PortfolioManager $portfolio, array $config, string $entryPrice): string
    {
        $riskPerTrade = (float) ($config['riskPerTrade'] ?? 1.0);
        $maxLeverage = (float) ($config['maxLeverage'] ?? 1.0);
        $defaultStopDistance = (float) ($config['defaultStopDistance'] ?? 5.0);

        if ($signal->stopLoss === null) {
            return OrderCalculator::riskBasedPositionSize(
                equity: $portfolio->getTotalEquity(),
                riskPerTrade: $riskPerTrade,
                entryPrice: $entryPrice,
                stopLoss: bcdiv(bcmul($entryPrice, (string) (100 - $defaultStopDistance), 12), '100', 12),
                maxLeverage: $maxLeverage,
            );
        }

        return OrderCalculator::riskBasedPositionSize(
            equity: $portfolio->getTotalEquity(),
            riskPerTrade: $riskPerTrade,
            entryPrice: $entryPrice,
            stopLoss: $signal->stopLoss,
            maxLeverage: $maxLeverage,
        );
    }
}
