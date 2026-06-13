<?php

namespace App\AlphaForge\Order\Sizing;

use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Model\PortfolioManager;

/**
 * Kelly Criterion position sizing.
 *
 *   f* = winRate - (1 - winRate) / (avgWin / avgLoss)
 *   positionSize = equity × f* × riskFraction
 *
 * Config keys:
 *   - winRate (float): Historical win rate (default 0.5)
 *   - avgWinLossRatio (float): Ratio of avg win to avg loss (default 1.0)
 *   - riskFraction (float): Fraction of full Kelly to use, e.g. 0.5 = half-Kelly (default 0.5)
 */
class KellySizer implements PositionSizer
{
    public function calculate(OrderSignal $signal, PortfolioManager $portfolio, array $config, string $entryPrice): string
    {
        $winRate = (float) ($config['winRate'] ?? 0.5);
        $avgWinLossRatio = (float) ($config['avgWinLossRatio'] ?? 1.0);
        $riskFraction = (float) ($config['riskFraction'] ?? 0.5);

        $lossRate = 1.0 - $winRate;

        if ($avgWinLossRatio <= 0) {
            return '0';
        }

        $kellyFraction = $winRate - ($lossRate / $avgWinLossRatio);

        if ($kellyFraction <= 0) {
            return '0';
        }

        $adjustedFraction = $kellyFraction * $riskFraction;
        $equity = $portfolio->getTotalEquity();

        return bcmul($equity, (string) $adjustedFraction, 12);
    }
}
