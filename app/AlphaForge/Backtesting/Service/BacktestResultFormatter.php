<?php

namespace App\AlphaForge\Backtesting\Service;

class BacktestResultFormatter
{
    /**
     * @param  array{final_capital: float|int|string, initial_capital: float|int|string, execution_timeframe?: string|null, statistics?: array<string, mixed>, positions?: array<object|array<string, mixed>>}  $result
     * @return array{final_capital: string, initial_capital: string, execution_timeframe: string|null, pnl: array{absolute: string, percent: string, color: string}}
     */
    public function formatCapitalSummary(array $result): array
    {
        $finalCapital = (float) $result['final_capital'];
        $initialCapital = (float) $result['initial_capital'];
        $profitLoss = $finalCapital - $initialCapital;
        $profitLossPercent = ($initialCapital > 0) ? (($finalCapital / $initialCapital) - 1) * 100 : 0.0;

        $formattedPnl = ($profitLoss >= 0 ? '+' : '').number_format($profitLoss, 2);
        $formattedPercent = ($profitLossPercent >= 0 ? '+' : '').number_format($profitLossPercent, 2);
        $pnlColor = $profitLoss >= 0 ? '<fg=green>' : '<fg=red>';

        return [
            'final_capital' => number_format($finalCapital, 2),
            'initial_capital' => number_format($initialCapital, 2),
            'execution_timeframe' => $result['execution_timeframe'] ?? null,
            'pnl' => [
                'absolute' => $formattedPnl,
                'percent' => $formattedPercent,
                'color' => $pnlColor,
            ],
        ];
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $stats
     * @return array<string, string>
     */
    public function formatStatistics(array $stats): array
    {
        $formatted = [];

        if (isset($stats['total_trades'])) {
            $formatted['Total Trades'] = (string) (int) $stats['total_trades'];
        }
        if (isset($stats['winning_trades'])) {
            $formatted['Winning Trades'] = (string) (int) $stats['winning_trades'];
        }
        if (isset($stats['losing_trades'])) {
            $formatted['Losing Trades'] = (string) (int) $stats['losing_trades'];
        }
        if (isset($stats['win_rate'])) {
            $formatted['Win Rate'] = number_format((float) $stats['win_rate'] * 100, 2).'%';
        }
        if (isset($stats['profit_factor'])) {
            $formatted['Profit Factor'] = number_format((float) $stats['profit_factor'], 2);
        }
        if (isset($stats['max_drawdown_percent'])) {
            $formatted['Max Drawdown'] = number_format((float) $stats['max_drawdown_percent'] * 100, 2).'%';
        }
        if (isset($stats['sharpe_ratio'])) {
            $formatted['Sharpe Ratio'] = number_format((float) $stats['sharpe_ratio'], 2);
        }
        if (isset($stats['total_return_percent'])) {
            $formatted['Total Return'] = number_format((float) $stats['total_return_percent'], 2).'%';
        }

        return $formatted;
    }

    /**
     * Format position objects for table display.
     *
     * @param  array<object|array{symbol?: string, direction?: string, entryPrice?: numeric, exitPrice?: numeric, realizedPnl?: numeric}>  $positions
     * @param  float  $initialCapital  Initial capital for running balance calculation
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    public function formatPositions(array $positions, float $initialCapital = 0.0): array
    {
        $result = [];
        $runningBalance = $initialCapital;

        foreach ($positions as $position) {
            if (is_array($position)) {
                $symbol = (string) ($position['symbol'] ?? '?');
                $direction = (string) ($position['direction'] ?? 'unknown');
                $entryPrice = (float) ($position['entryPrice'] ?? 0);
                $exitPrice = (float) ($position['exitPrice'] ?? 0);
                $pnl = (float) ($position['realizedPnl'] ?? 0);
            } else {
                /** @var string $symbol */
                $symbol = $position->symbol ?? '?';
                /** @var string $direction */
                $direction = $position->direction ?? 'unknown';
                /** @var float $entryPrice */
                $entryPrice = $position->entryPrice ?? 0;
                /** @var float $exitPrice */
                $exitPrice = $position->exitPrice ?? 0;
                /** @var float $pnl */
                $pnl = $position->realizedPnl ?? 0;
            }

            $runningBalance += $pnl;

            $result[] = [
                $symbol,
                $direction,
                number_format($entryPrice, 2),
                number_format($exitPrice, 2),
                number_format($pnl, 2),
                number_format($runningBalance, 2),
            ];
        }

        return $result;
    }
}
