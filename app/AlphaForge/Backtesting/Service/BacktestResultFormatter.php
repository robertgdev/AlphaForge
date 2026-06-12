<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Common\Enum\TimeframeEnum;
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
            $formatted['Total Return'] = number_format($returnPct, 2).'%';
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
            $label = $lowTrades ? 'Portfolio Sharpe (low confidence)' : 'Portfolio Sharpe';

            $formatted[$label] = $color.number_format($sharpeVal, 2).'</>';
        }

        if (isset($stats['active_sharpe_ratio'])) {
            $activeSharpe = (float) $stats['active_sharpe_ratio'];
            $sharpeVal = (float) ($stats['sharpe_ratio'] ?? 0);
            $diffPct = $sharpeVal > 0 ? abs(($activeSharpe - $sharpeVal) / $sharpeVal) * 100 : 100;

            if ($diffPct >= 10) {
                $activeColor = $activeSharpe > 0 ? '<fg=green>' : '<fg=red>';
                $activeLabel = $lowTrades ? 'Active Sharpe (low confidence)' : 'Active Sharpe';

                $returnPct = abs((float) ($stats['total_return_percent'] ?? 0));
                $suspiciousSharpe = $activeSharpe > 5.0 && $returnPct < 5.0;

                if ($suspiciousSharpe) {
                    $activeLabel .= ' ⚠';
                    $formatted[$activeLabel] = $activeColor.number_format($activeSharpe, 2).'</> <fg=yellow>(high Sharpe with minimal absolute returns — may reflect low exposure rather than exceptional risk-adjusted performance)</>';
                } else {
                    $formatted[$activeLabel] = $activeColor.number_format($activeSharpe, 2).'</>';
                }
            }
        }

        if (isset($stats['sortino_ratio'])) {
            $sortinoVal = (float) $stats['sortino_ratio'];
            $color = $sortinoVal > 0 ? '<fg=green>' : '<fg=red>';
            $label = $lowTrades ? 'Portfolio Sortino (low confidence)' : 'Portfolio Sortino';
            $formatted[$label] = $color.number_format($sortinoVal, 2).'</>';
        }

        if (isset($stats['active_sortino_ratio'])) {
            $activeSortino = (float) $stats['active_sortino_ratio'];
            $sortinoVal = (float) ($stats['sortino_ratio'] ?? 0);
            $diffPct = $sortinoVal > 0 ? abs(($activeSortino - $sortinoVal) / $sortinoVal) * 100 : 100;

            if ($diffPct >= 10) {
                $activeColor = $activeSortino > 0 ? '<fg=green>' : '<fg=red>';
                $activeLabel = $lowTrades ? 'Active Sortino (low confidence)' : 'Active Sortino';
                $formatted[$activeLabel] = $activeColor.number_format($activeSortino, 2).'</>';
            }
        }
        if (isset($stats['volatility'])) {
            $formatted['Volatility (Ann.)'] = number_format((float) $stats['volatility'] * 100, 2).'%';
        }
        if (isset($stats['trading_days'])) {
            $formatted['Trading Days'] = (string) (int) $stats['trading_days'];
        }
        if (isset($stats['time_in_market_percent']) && (float) $stats['time_in_market_percent'] > 0) {
            $formatted['Time in Market'] = number_format((float) $stats['time_in_market_percent'], 2).'%';
        }
        if (isset($stats['idle_capital_percent']) && (float) $stats['idle_capital_percent'] > 0) {
            $formatted['Idle Capital'] = number_format((float) $stats['idle_capital_percent'], 2).'%';
        }

        return $formatted;
    }

    /**
     * Format trade distribution statistics from the statistics array.
     *
     * @param  array<string, mixed>  $stats
     * @param  string|null  $timeframe  Timeframe string (e.g. '1h', '4h', '1d') for converting bars to elapsed time
     * @return array<string, string>
     */
    public function formatTradeDistribution(array $stats, ?string $timeframe = null): array
    {
        $formatted = [];

        $totalTrades = (int) ($stats['total_trades'] ?? 0);
        $formatted['Total trades'] = (string) $totalTrades;

        if (isset($stats['max_consecutive_wins'])) {
            $formatted['Max consecutive wins'] = (string) (int) $stats['max_consecutive_wins'];
        }
        if (isset($stats['max_consecutive_losses'])) {
            $formatted['Max consecutive losses'] = (string) (int) $stats['max_consecutive_losses'];
        }

        if ($totalTrades > 0) {
            $avgWinStreak = $this->averageConsecutiveStreak($stats, 'win');
            $avgLossStreak = $this->averageConsecutiveStreak($stats, 'loss');
            $formatted['Average win streak'] = $avgWinStreak !== null ? number_format($avgWinStreak, 1) : '-';
            $formatted['Average loss streak'] = $avgLossStreak !== null ? number_format($avgLossStreak, 1) : '-';
        }

        if (isset($stats['largest_win'])) {
            $formatted['Largest win'] = number_format(abs((float) $stats['largest_win']), 2);
        }
        if (isset($stats['largest_loss'])) {
            $formatted['Largest loss'] = '-'.number_format(abs((float) $stats['largest_loss']), 2);
        }
        if (isset($stats['average_win'])) {
            $formatted['Average win'] = number_format((float) $stats['average_win'], 2);
        }
        if (isset($stats['average_loss'])) {
            $formatted['Average loss'] = '-'.number_format(abs((float) $stats['average_loss']), 2);
        }
        if (isset($stats['expectancy'])) {
            $formatted['Expectancy/trade'] = (float) $stats['expectancy'] >= 0
                ? '+'.number_format((float) $stats['expectancy'], 2)
                : number_format((float) $stats['expectancy'], 2);
        }
        if (isset($stats['max_drawdown_duration'])) {
            $duration = (int) $stats['max_drawdown_duration'];
            $formatted['Recovery time (max, bars)'] = (string) $duration;

            if ($timeframe !== null) {
                $elapsed = $this->barsToElapsed($duration, $timeframe);
                if ($elapsed !== null) {
                    $formatted['Recovery time (max, elapsed)'] = $elapsed;
                }
            }
        }

        return $formatted;
    }

    /**
     * Format holding time statistics (trade durations).
     *
     * @param  array<string, mixed>  $stats
     * @param  string|null  $timeframe  Timeframe string for converting bars to elapsed time where needed
     * @return array<string, string>
     */
    public function formatHoldingTime(array $stats, ?string $timeframe = null): array
    {
        $formatted = [];

        if (isset($stats['average_trade_duration'])) {
            $formatted['Average duration'] = $this->formatDurationSeconds((int) $stats['average_trade_duration'], $timeframe);
        }
        if (isset($stats['median_trade_duration'])) {
            $formatted['Median duration'] = $this->formatDurationSeconds((int) $stats['median_trade_duration'], $timeframe);
        }
        if (isset($stats['min_trade_duration'])) {
            $formatted['Shortest trade'] = $this->formatDurationSeconds((int) $stats['min_trade_duration'], $timeframe);
        }
        if (isset($stats['max_trade_duration'])) {
            $formatted['Longest trade'] = $this->formatDurationSeconds((int) $stats['max_trade_duration'], $timeframe);
        }

        return $formatted;
    }

    /**
     * Format drawdown statistics.
     *
     * @param  array<string, mixed>  $stats
     * @param  string|null  $timeframe  Timeframe string for converting bars to elapsed time
     * @return array<string, string>
     */
    public function formatDrawdownStatistics(array $stats, ?string $timeframe = null): array
    {
        $formatted = [];

        if (isset($stats['max_drawdown_percent'])) {
            $formatted['Maximum drawdown'] = number_format(abs((float) $stats['max_drawdown_percent']) * 100, 2).'%';
        }
        if (isset($stats['avg_drawdown'])) {
            $formatted['Average drawdown'] = number_format((float) $stats['avg_drawdown'], 2);
        }
        if (isset($stats['max_drawdown_duration'])) {
            $formatted['Maximum recovery time'] = $this->barsToElapsedOrBars((int) $stats['max_drawdown_duration'], $timeframe);
        }
        if (isset($stats['avg_drawdown_duration'])) {
            $formatted['Average recovery time'] = $this->barsToElapsedOrBars((int) $stats['avg_drawdown_duration'], $timeframe);
        }
        // max_drawdown_duration is already the longest underwater period
        // but the block also asks for "Longest underwater period" separately
        if (isset($stats['max_drawdown_duration'])) {
            $formatted['Longest underwater period'] = $this->barsToElapsedOrBars((int) $stats['max_drawdown_duration'], $timeframe);
        }

        return $formatted;
    }

    /**
     * Format a duration in seconds to a human-readable string.
     * Falls back to raw seconds if no timeframe for context.
     */
    private function formatDurationSeconds(int $seconds, ?string $timeframe): string
    {
        if ($timeframe !== null) {
            return $this->secondsToElapsed($seconds);
        }

        return "{$seconds}s";
    }

    /**
     * Convert seconds to a human-readable elapsed time string.
     */
    private function secondsToElapsed(int $totalSeconds): string
    {
        $days = intdiv($totalSeconds, 86400);
        $remaining = $totalSeconds % 86400;
        $hours = intdiv($remaining, 3600);
        $remaining %= 3600;
        $minutes = intdiv($remaining, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0 || empty($parts)) {
            $parts[] = "{$minutes}m";
        }

        return implode(' ', $parts);
    }

    /**
     * Format bar count as elapsed time, or bare bar count if no timeframe.
     */
    private function barsToElapsedOrBars(int $bars, ?string $timeframe): string
    {
        if ($timeframe !== null) {
            $elapsed = $this->barsToElapsed($bars, $timeframe);
            if ($elapsed !== null) {
                return $elapsed;
            }
        }

        return "{$bars} bars";
    }

    /**
     * Format exit reason distribution from position data.
     *
     * @param  array<object|array{symbol?: string, direction?: string, exitTag?: string|null}>  $positions
     * @return array<string, array{count: int, pct: float, label: string}>
     */
    public function formatExitReasonDistribution(array $positions): array
    {
        $counts = [];
        $total = 0;

        foreach ($positions as $position) {
            $exitTag = is_array($position)
                ? (string) ($position['exitTag'] ?? 'unknown')
                : (string) ($position->exitTag ?? 'unknown');

            $label = match ($exitTag) {
                'stop_loss' => 'Stop Loss',
                'take_profit' => 'Take Profit',
                'strategy_signal' => 'Strategy Signal',
                'counter_signal' => 'Counter Signal',
                'end_of_backtest' => 'End of Backtest',
                'trailing_stop' => 'Trailing Stop',
                default => $exitTag,
            };

            $key = $label;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $total++;
        }

        $distribution = [];
        foreach ($counts as $label => $count) {
            $distribution[$label] = [
                'count' => $count,
                'pct' => $total > 0 ? ($count / $total) * 100 : 0,
                'label' => $label,
            ];
        }

        uasort($distribution, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $distribution;
    }

    /**
     * Compute average consecutive streak length from position data.
     * Falls back to estimating from max_consecutive + win_rate if no position-level data available.
     *
     * @param  array<string, mixed>  $stats
     */
    private function averageConsecutiveStreak(array $stats, string $type): ?float
    {
        $winRate = (float) ($stats['win_rate'] ?? 0);
        $total = (int) ($stats['total_trades'] ?? 0);

        if ($total < 2) {
            return null;
        }

        $wins = (int) ($stats['winning_trades'] ?? 0);
        $losses = (int) ($stats['losing_trades'] ?? 0);

        if ($type === 'win' && $wins < 1) {
            return 0.0;
        }
        if ($type === 'loss' && $losses < 1) {
            return 0.0;
        }

        // Geometric distribution: E[streak_length] = 1 / (1 - p) for streaks of type with probability p
        if ($type === 'win' && $winRate > 0 && $winRate < 1) {
            return 1.0 / (1.0 - $winRate);
        }
        if ($type === 'loss' && $winRate > 0 && $winRate < 1) {
            return 1.0 / $winRate;
        }

        return null;
    }

    /**
     * Convert a bar count to a human-readable elapsed time string.
     *
     * @return string|null e.g. "2d 5h 30m" or null if timeframe is invalid
     */
    private function barsToElapsed(int $bars, string $timeframe): ?string
    {
        $tf = TimeframeEnum::tryFrom($timeframe);
        if ($tf === null) {
            return null;
        }

        $totalSeconds = $bars * $tf->toSeconds();

        $days = intdiv($totalSeconds, 86400);
        $remaining = $totalSeconds % 86400;
        $hours = intdiv($remaining, 3600);
        $remaining %= 3600;
        $minutes = intdiv($remaining, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0 || empty($parts)) {
            $parts[] = "{$minutes}m";
        }

        return implode(' ', $parts);
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
