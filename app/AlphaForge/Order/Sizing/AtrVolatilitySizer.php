<?php

namespace App\AlphaForge\Order\Sizing;

use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Model\PortfolioManager;

/**
 * Sizes inversely proportional to ATR-based volatility.
 *
 *   positionSize = equity × riskPerTrade / (ATR_multiple × atrPct)
 *
 * When a signal uses ATR stops (BreakoutStrategy), this sizer allocates
 * the same dollar risk regardless of the ATR level — wider stops mean
 * smaller positions, tighter stops mean larger positions.
 *
 * Config keys:
 *   - riskPerTrade (float): Percentage of equity risked per trade (default 1.0)
 *   - atrPercent (float): ATR as percentage of price (default 2.0, fallback if no ATR on signal)
 */
class AtrVolatilitySizer implements PositionSizer
{
    public function calculate(OrderSignal $signal, PortfolioManager $portfolio, array $config, string $entryPrice): string
    {
        $riskPerTrade = (float) ($config['riskPerTrade'] ?? 1.0);
        $defaultAtrPercent = (float) ($config['atrPercent'] ?? 2.0);

        $equity = $portfolio->getTotalEquity();

        if ($signal->stopLoss === null) {
            $atrPercent = $defaultAtrPercent;
        } else {
            $stopDistance = bcsub($entryPrice, $signal->stopLoss, 12);
            $atrPercent = (float) bcmul(bcdiv($stopDistance, $entryPrice, 12), '100', 12);
            if ($atrPercent <= 0) {
                $atrPercent = $defaultAtrPercent;
            }
        }

        $riskAmount = bcdiv(bcmul($equity, (string) $riskPerTrade, 12), '100', 12);

        return bcdiv($riskAmount, bcdiv((string) $atrPercent, '100', 12), 12);
    }
}
