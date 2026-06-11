<?php

namespace App\AlphaForge\Backtesting\Service;

use Carbon\Carbon;

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

        if (isset($stats['total_return_percent'])) {
            $returnPct = (float) $stats['total_return_percent'];
            $precision = abs($returnPct) < 1.0 ? 4 : 2;
            $formatted['Total Return'] = number_format($returnPct, $precision).'%';
        }
        if (isset($stats['cagr'])) {
            $cagrPct = number_format((float) $stats['cagr'] * 100, 2).'%';
            $formatted['CAGR'] = $cagrPct;
        }
        if (isset($stats['total_trades'])) {
            $tradeCount = (int) $stats['total_trades'];
            $label = 'Total Trades';
            if ($tradeCount > 0 && $tradeCount < 30) {
                $label = 'Total Trades ⚠';
            }
            $formatted[$label] = (string) $tradeCount;
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
        $lowTrades = ((int) ($stats['total_trades'] ?? 999)) < 30 && ((int) ($stats['total_trades'] ?? 0)) > 0;

        if (isset($stats['sharpe_ratio'])) {
            $sharpeVal = (float) $stats['sharpe_ratio'];
            $color = $sharpeVal > 0 ? '<fg=green>' : '<fg=red>';
            $label = $lowTrades ? 'Sharpe Ratio (low confidence)' : 'Sharpe Ratio';
            $formatted[$label] = $color.number_format($sharpeVal, 2).'</>';
        }
        if (isset($stats['sortino_ratio'])) {
            $sortinoVal = (float) $stats['sortino_ratio'];
            $color = $sortinoVal > 0 ? '<fg=green>' : '<fg=red>';
            $label = $lowTrades ? 'Sortino Ratio (low confidence)' : 'Sortino Ratio';
            $formatted[$label] = $color.number_format($sortinoVal, 2).'</>';
        }
        if (isset($stats['volatility'])) {
            $formatted['Volatility (Ann.)'] = number_format((float) $stats['volatility'] * 100, 2).'%';
        }
        if (isset($stats['trading_days'])) {
            $formatted['Trading Days'] = (string) (int) $stats['trading_days'];
        }

        return $formatted;
    }

    /**
     * Format position objects for table display.
     *
     * @param  array<object|array{symbol?: string, direction?: string, entryPrice?: numeric, exitPrice?: numeric, realizedPnl?: numeric, exitTag?: string|null, entryTime?: Carbon|null, exitTime?: Carbon|null}>  $positions
     * @param  float  $initialCapital  Initial capital for running balance calculation
     * @param  bool  $noColor  Disable color output for PnL column
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string, 9: string}>
     */
    public function formatPositions(array $positions, float $initialCapital = 0.0, bool $noColor = false): array
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
                $exitTag = (string) ($position['exitTag'] ?? '-');
                $entryTime = $position['entryTime'] ?? null;
                $exitTime = $position['exitTime'] ?? null;
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
                /** @var string|null $exitTag */
                $exitTag = $position->exitTag ?? '-';
                /** @var Carbon|null $entryTime */
                $entryTime = $position->entryTime ?? null;
                /** @var Carbon|null $exitTime */
                $exitTime = $position->exitTime ?? null;
            }

            $runningBalance += $pnl;

            $duration = '-';
            if ($entryTime instanceof Carbon && $exitTime instanceof Carbon) {
                $diff = $entryTime->diff($exitTime);
                $parts = [];
                if ($diff->d > 0) {
                    $parts[] = $diff->d.'d';
                }
                if ($diff->h > 0) {
                    $parts[] = $diff->h.'h';
                }
                if ($diff->i > 0) {
                    $parts[] = $diff->i.'m';
                }
                if (empty($parts)) {
                    $parts[] = $diff->s.'s';
                }
                $duration = implode(' ', $parts);
            }

            $formattedPnl = number_format($pnl, 2);
            if (! $noColor) {
                $formattedPnl = $pnl >= 0
                    ? "\033[32m{$formattedPnl}\033[0m"
                    : "\033[31m{$formattedPnl}\033[0m";
            }

            $result[] = [
                $symbol,
                $direction,
                $entryTime instanceof Carbon ? $entryTime->format('Y-m-d H:i:s') : '-',
                $exitTime instanceof Carbon ? $exitTime->format('Y-m-d H:i:s') : '-',
                $duration,
                number_format($entryPrice, 2),
                number_format($exitPrice, 2),
                $formattedPnl,
                number_format($runningBalance, 2),
                $exitTag ?: '-',
            ];
        }

        return $result;
    }
}
