<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Model\OptimizationRun;
use App\AlphaForge\Backtesting\Optimization\Sensitivity\ParameterSensitivityService;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use Illuminate\Console\Command;

class SensitivityAnalysisCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:optimizations:sensitivity
        {optimization_id : The optimization run ID}
        {--metric=optimization_score : Metric to analyze}
        {--surface= : Comma-separated pair of parameters for 2D heatmap (e.g. fastPeriod,slowPeriod)}
        {--interactions : Show inter-parameter interaction effects}
        {--json : Output results as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Analyze parameter sensitivity from an optimization run';

    public function handle(): int
    {
        $optimizationId = $this->argument('optimization_id');

        $optimizationRun = OptimizationRun::find($optimizationId);

        if (! $optimizationRun) {
            return $this->outputJsonError("Optimization run not found: $optimizationId");
        }

        if (! $optimizationRun->isCompleted()) {
            return $this->outputJsonError('Optimization run is not completed. Status: '.$optimizationRun->status);
        }

        $metric = $this->option('metric');

        if (! $this->jsonEnabled()) {
            $this->line('<fg=yellow>=== Parameter Sensitivity Analysis ===</>');
            $this->line("  Optimization ID: {$optimizationRun->id}");
            $this->line("  Strategy: {$optimizationRun->strategy_alias}");
            $this->line('  Symbol: '.($optimizationRun->symbols[0] ?? '-'));
            $this->line("  Method: {$optimizationRun->optimization_method}");
            $this->line("  Metric: {$metric}");
            $this->newLine();
        }

        $service = ParameterSensitivityService::fromOptimizationRunId($optimizationId);

        $totalRuns = BacktestRun::where('optimization_id', $optimizationId)->where('status', 'completed')->count();

        if (! $this->jsonEnabled()) {
            $this->line("  Results analyzed: {$totalRuns}");
            $this->newLine();
        }

        if ($totalRuns === 0) {
            return $this->outputJsonError('No completed backtest results found for this optimization.');
        }

        if ($this->jsonEnabled()) {
            $data = [
                'optimizationId' => $optimizationRun->id,
                'strategy' => $optimizationRun->strategy_alias,
                'symbol' => $optimizationRun->symbols[0] ?? null,
                'metric' => $metric,
                'totalRuns' => $totalRuns,
                'importance' => $service->importance($metric),
            ];

            if ($surfaceParam = $this->option('surface')) {
                $parts = array_map('trim', explode(',', $surfaceParam));
                if (count($parts) !== 2) {
                    return $this->outputJsonError('--surface requires exactly 2 parameter names separated by comma.');
                }
                try {
                    $data['surface'] = $service->surface($parts[0], $parts[1], $metric);
                } catch (\InvalidArgumentException $e) {
                    return $this->outputJsonError($e->getMessage());
                }
            }

            if ($this->option('interactions')) {
                $data['interactions'] = $service->interactions($metric);
            }

            return $this->outputJson(true, $data);
        }

        // Always show parameter importance
        $this->renderImportance($service, $metric);

        // Optional: 2D surface
        if ($surfaceParam = $this->option('surface')) {
            $parts = array_map('trim', explode(',', $surfaceParam));
            if (count($parts) !== 2) {
                return $this->outputJsonError('--surface requires exactly 2 parameter names separated by comma.');
            }

            $this->renderSurface($service, $parts[0], $parts[1], $metric);
        }

        // Optional: interaction effects
        if ($this->option('interactions')) {
            $this->renderInteractions($service, $metric);
        }

        $this->debugMemory();

        return 0;
    }

    private function renderImportance(ParameterSensitivityService $service, string $metric): void
    {
        $this->line('<fg=yellow>Parameter Importance</>');
        $this->line('  How much each parameter contributes to variation in the objective score.');
        $this->line('  High importance → this parameter strongly drives performance.');
        $this->line('  Low importance  → this parameter has little impact (likely safe to fix).');
        $this->newLine();

        $importance = $service->importance($metric);

        if (empty($importance)) {
            $this->line('  No parameters found to analyze.');

            return;
        }

        $tableData = array_map(function ($row) {
            $bar = $this->importanceBar((float) $row['importance_pct']);
            $display = $this->formatImportancePct((float) $row['importance_pct'], (float) ($row['raw_importance_pct'] ?? 0));

            return [
                $row['param'],
                number_format($row['variance'], 6),
                $display,
                $bar,
            ];
        }, $importance);

        $this->table(
            ['Parameter', 'Score Variance', 'Importance %', ''],
            $tableData
        );

        if (count($importance) > 1) {
            $top = $importance[0]['param'];
            $bottom = $importance[count($importance) - 1]['param'];
            $topPct = (float) $importance[0]['importance_pct'];
            $topRawPct = (float) ($importance[0]['raw_importance_pct'] ?? 0);
            $topDisplay = $this->formatImportancePct($topPct, $topRawPct);

            $this->newLine();
            $this->line("  <fg=green>Top driver: {$top}</> ({$topDisplay} of score variance)");
            $this->line('  Consider focusing optimization on the highest-importance parameters.');
            $this->newLine();

            $strongParams = [];
            $moderateParams = [];
            $weakParams = [];
            foreach ($importance as $row) {
                $rawPct = (float) ($row['raw_importance_pct'] ?? 0);
                $name = $row['param'];
                $bar = $this->importanceBar($rawPct);
                if ($rawPct >= 40) {
                    $strongParams[] = "  {$bar}\n    <fg=green>{$name}:</> Strong influence. Small changes materially affect performance. Prioritize optimization.";
                } elseif ($rawPct >= 15) {
                    $moderateParams[] = "  {$bar}\n    {$name}: Moderate influence. Fine-tune with care.";
                } else {
                    $weakParams[] = "  {$bar}\n    {$name}: Weak influence. Likely safe to fix during future searches.";
                }
            }

            if (! empty($strongParams)) {
                $this->newLine();
                foreach ($strongParams as $p) {
                    $this->line($p);
                }
            }
            if (! empty($moderateParams)) {
                if (! empty($strongParams)) {
                    $this->newLine();
                }
                foreach ($moderateParams as $p) {
                    $this->line($p);
                }
            }
            if (! empty($weakParams)) {
                if (! empty($strongParams) || ! empty($moderateParams)) {
                    $this->newLine();
                }
                foreach ($weakParams as $p) {
                    $this->line($p);
                }
            }

            $this->newLine();
            if (! empty($strongParams)) {
                $strongNames = array_map(fn ($row) => $row['param'], array_filter($importance, fn ($row) => ((float) ($row['raw_importance_pct'] ?? 0)) >= 40));
                $weakNames = array_map(fn ($row) => $row['param'], array_filter($importance, fn ($row) => ((float) ($row['raw_importance_pct'] ?? 0)) < 15));
                $this->line('  Recommendation: Focus optimization on '.implode(', ', $strongNames).'.');
                if (! empty($weakNames)) {
                    $this->line('  '.implode(', ', $weakNames).' can be fixed to reduce search dimensionality.');
                }
            } else {
                $this->line('  No single parameter dominates performance. The strategy appears to rely');
                $this->line('  on multiple interacting inputs, suggesting that optimization should');
                $this->line('  consider them jointly rather than fixing any one parameter prematurely.');
            }
            $this->newLine();
        }
    }

    private function renderSurface(ParameterSensitivityService $service, string $paramA, string $paramB, string $metric): void
    {
        $this->line('<fg=yellow>2D Score Surface: '.$paramA.' × '.$paramB.'</>');

        try {
            $result = $service->surface($paramA, $paramB, $metric);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return;
        }

        $rows = $result['rows'];
        $cols = $result['cols'];
        $grid = $result['grid'];
        $bestScore = $result['best_score'];
        $worstScore = $result['worst_score'];

        $this->line('  Best score: <fg=green>'.number_format($bestScore, 4).'</>');
        $this->line('  Worst score: <fg=red>'.number_format($worstScore, 4).'</>');
        $this->line('  Stability: <fg='.($result['stability_score'] > 60 ? 'green' : ($result['stability_score'] > 30 ? 'yellow' : 'red')).'>'.$result['stability_score'].'%</>');
        $this->line('    > 60% = flat plateau (robust optimum)');
        $this->line('    30-60% = moderate stability');
        $this->line('    < 30% = sharp peak (fragile optimum, likely overfit)');
        $this->newLine();

        $this->renderASCIIHeatmap($paramA, $paramB, $rows, $cols, $grid, $bestScore, $worstScore);
    }

    /**
     * @param  array<int, float|int>  $rows
     * @param  array<int, float|int>  $cols
     * @param  array<int, array<int, float|null>>  $grid
     */
    private function renderASCIIHeatmap(string $paramA, string $paramB, array $rows, array $cols, array $grid, float $best, float $worst): void
    {
        $range = $best - $worst;
        if ($range <= 0) {
            $range = 1;
        }

        // Header
        $colHeader = str_pad("{$paramA} ↓ \\ {$paramB} →", 14);
        foreach ($cols as $col) {
            $colHeader .= str_pad((string) $col, 6, ' ', STR_PAD_LEFT);
        }
        $this->line('  '.$colHeader);
        $this->line('  '.str_repeat('─', 14 + count($cols) * 6));

        foreach ($rows as $rIdx => $rowVal) {
            $line = '  '.str_pad((string) $rowVal, 14);

            foreach ($cols as $cIdx => $colVal) {
                $val = $grid[$rIdx][$cIdx] ?? null;
                if ($val === null) {
                    $line .= "\033[90m    · \033[0m";

                    continue;
                }

                $normalized = ($val - $worst) / $range;
                $char = $this->heatmapChar($normalized);
                $line .= $this->heatmapColor($normalized).'  '.$char.' '.$char.' '."\033[0m";
            }
            $this->line($line);
        }
        $this->newLine();

        $this->line('  Legend: ░ = coldest  ▒ = cool  ▓ = warm  █ = hottest (best)');
        $this->newLine();
    }

    private function heatmapChar(float $normalized): string
    {
        if ($normalized >= 0.875) {
            return '█';
        }
        if ($normalized >= 0.625) {
            return '▓';
        }
        if ($normalized >= 0.375) {
            return '▒';
        }

        return '░';
    }

    private function heatmapColor(float $normalized): string
    {
        if ($normalized >= 0.875) {
            return "\033[32m"; // green (best)
        }
        if ($normalized >= 0.625) {
            return "\033[92m"; // bright green
        }
        if ($normalized >= 0.375) {
            return "\033[33m"; // yellow
        }
        if ($normalized >= 0.125) {
            return "\033[91m"; // bright red
        }

        return "\033[31m"; // red (worst)
    }

    private function renderInteractions(ParameterSensitivityService $service, string $metric): void
    {
        $this->line('<fg=yellow>Parameter Interaction Effects</>');
        $this->line('  Large positive interaction = parameters are non-separable.');
        $this->line('  The optimal value of one depends on the value of the other.');
        $this->newLine();

        $interactions = $service->interactions($metric);

        if (empty($interactions)) {
            $this->line('  Not enough parameters for interaction analysis (need ≥ 2).');

            return;
        }

        $topN = min(10, count($interactions));
        $display = array_slice($interactions, 0, $topN);

        $tableData = array_map(function ($row) {
            $interactionLevel = '';
            if ($row['interaction'] > 0.01) {
                $interactionLevel = '<fg=red>STRONG</>';
            } elseif ($row['interaction'] > 0.001) {
                $interactionLevel = '<fg=yellow>MODERATE</>';
            } else {
                $interactionLevel = '<fg=gray>WEAK</>';
            }

            return [
                $row['param_a'].' × '.$row['param_b'],
                number_format($row['joint_variance'], 6),
                number_format($row['interaction'], 6),
                $interactionLevel,
            ];
        }, $display);

        $this->table(
            ['Parameter Pair', 'Joint Variance', 'Interaction', 'Strength'],
            $tableData
        );
    }

    private function importanceBar(float $pct): string
    {
        $width = 20;
        $filled = (int) round($pct / 100 * $width);

        if ($pct >= 60) {
            return '<fg=green>'.str_repeat('█', max(1, $filled)).'</>';
        }
        if ($pct >= 30) {
            return '<fg=yellow>'.str_repeat('▓', max(1, $filled)).'</>';
        }

        return '<fg=gray>'.str_repeat('▒', max(1, $filled)).'</>';
    }

    private function formatImportancePct(float $rounded, float $raw): string
    {
        if ($rounded === 0.0 && $raw > 0) {
            return '<0.1%';
        }

        return number_format($rounded, 1).'%';
    }
}
