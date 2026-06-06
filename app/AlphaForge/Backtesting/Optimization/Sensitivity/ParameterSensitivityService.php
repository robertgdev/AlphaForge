<?php

namespace App\AlphaForge\Backtesting\Optimization\Sensitivity;

use App\AlphaForge\Backtesting\Model\BacktestRun;

class ParameterSensitivityService
{
    /**
     * @param  list<array{params: array<string, mixed>, stats: array<string, mixed>}>  $runs
     */
    public function __construct(
        private readonly array $runs,
    ) {}

    public static function fromOptimizationRunId(string $optimizationId): self
    {
        $runs = BacktestRun::where('optimization_id', $optimizationId)
            ->where('status', 'completed')
            ->get();

        $data = [];
        foreach ($runs as $run) {
            $data[] = [
                'params' => $run->strategy_inputs,
                'stats' => $run->statistics,
            ];
        }

        return new self($data);
    }

    /**
     * Compute parameter importance scores.
     *
     * For each parameter, computes how much the score varies across its values,
     * normalized as a percentage of total variance across all parameters.
     *
     * @param  string  $metric  Metric name in statistics (default: optimization_score)
     * @return array<int, array{param: string, variance: float, importance_pct: float}>
     */
    public function importance(string $metric = 'optimization_score'): array
    {
        $paramNames = $this->parameterNames();

        $variances = [];
        foreach ($paramNames as $param) {
            $variances[$param] = $this->marginalVariance($param, $metric);
        }

        $totalVariance = array_sum($variances);

        if ($totalVariance <= 0) {
            return array_map(fn ($param) => [
                'param' => $param,
                'variance' => 0.0,
                'importance_pct' => 0.0,
            ], $paramNames);
        }

        $results = [];
        foreach ($paramNames as $param) {
            $results[] = [
                'param' => $param,
                'variance' => round($variances[$param], 6),
                'importance_pct' => round(($variances[$param] / $totalVariance) * 100, 1),
            ];
        }

        usort($results, fn ($a, $b) => $b['importance_pct'] <=> $a['importance_pct']);

        return $results;
    }

    /**
     * Build a 2D score surface for a pair of parameters.
     *
     * Groups results by unique values of paramA and paramB, computing
     * the mean score for each cell.
     *
     * @param  string  $paramA  First parameter name
     * @param  string  $paramB  Second parameter name
     * @param  string  $metric  Metric name in statistics
     * @return array{rows: array<int, float|int>, cols: array<int, float|int>, grid: array<int, array<int, float|null>>, best_score: float, worst_score: float, stability_score: float}
     */
    public function surface(string $paramA, string $paramB, string $metric = 'optimization_score'): array
    {
        $rowValues = [];
        $colValues = [];

        // Collect all unique param values and accumulate scores
        $cellScores = [];
        $cellCounts = [];

        foreach ($this->runs as $run) {
            $aVal = $run['params'][$paramA] ?? null;
            $bVal = $run['params'][$paramB] ?? null;
            $score = (float) ($run['stats'][$metric] ?? 0);

            if ($aVal === null || $bVal === null) {
                continue;
            }

            $rowValues[$aVal] = true;
            $colValues[$bVal] = true;

            if (! isset($cellScores[$aVal][$bVal])) {
                $cellScores[$aVal][$bVal] = 0.0;
                $cellCounts[$aVal][$bVal] = 0;
            }

            $cellScores[$aVal][$bVal] += $score;
            $cellCounts[$aVal][$bVal]++;
        }

        $rows = array_keys($rowValues);
        $cols = array_keys($colValues);
        sort($rows);
        sort($cols);

        $grid = [];
        $bestScore = PHP_FLOAT_MIN;
        $worstScore = PHP_FLOAT_MAX;

        foreach ($rows as $rIdx => $rVal) {
            foreach ($cols as $cIdx => $cVal) {
                if (isset($cellScores[$rVal][$cVal]) && $cellCounts[$rVal][$cVal] > 0) {
                    $avg = $cellScores[$rVal][$cVal] / $cellCounts[$rVal][$cVal];
                    $grid[$rIdx][$cIdx] = round($avg, 4);
                    $bestScore = max($bestScore, $avg);
                    $worstScore = min($worstScore, $avg);
                } else {
                    $grid[$rIdx][$cIdx] = null;
                }
            }
        }

        // Compute stability: how much does score degrade within 1 step of optimum?
        $stabilityScore = $this->computeStability($grid, $rows, $cols, $bestScore);

        return [
            'rows' => $rows,
            'cols' => $cols,
            'grid' => $grid,
            'best_score' => $bestScore,
            'worst_score' => $worstScore,
            'stability_score' => $stabilityScore,
        ];
    }

    /**
     * Detect parameter interaction effects.
     *
     * Computes interaction magnitude for every parameter pair:
     * interaction = Var(score | A, B) - Var(score | A) - Var(score | B)
     *
     * A large positive interaction means the parameters are non-separable —
     * the best value of A depends on the value of B.
     *
     * @param  string  $metric  Metric name in statistics
     * @return array<int, array{param_a: string, param_b: string, joint_variance: float, interaction: float}>
     */
    public function interactions(string $metric = 'optimization_score'): array
    {
        $paramNames = $this->parameterNames();

        if (count($paramNames) < 2) {
            return [];
        }

        // Compute marginal variances for each parameter
        $marginalVariances = [];
        foreach ($paramNames as $param) {
            $marginalVariances[$param] = $this->marginalVariance($param, $metric);
        }

        $results = [];
        for ($i = 0; $i < count($paramNames); $i++) {
            for ($j = $i + 1; $j < count($paramNames); $j++) {
                $a = $paramNames[$i];
                $b = $paramNames[$j];

                $jointVar = $this->jointVariance($a, $b, $metric);
                $interaction = $jointVar - $marginalVariances[$a] - $marginalVariances[$b];

                $results[] = [
                    'param_a' => $a,
                    'param_b' => $b,
                    'joint_variance' => round($jointVar, 6),
                    'interaction' => round($interaction, 6),
                ];
            }
        }

        usort($results, fn ($a, $b) => $b['interaction'] <=> $a['interaction']);

        return $results;
    }

    /**
     * Compute the variance of mean scores across different values of one parameter.
     */
    private function marginalVariance(string $paramName, string $metric): float
    {
        $grouped = [];
        $counts = [];

        foreach ($this->runs as $run) {
            $val = $run['params'][$paramName] ?? null;
            if ($val === null) {
                continue;
            }

            $score = (float) ($run['stats'][$metric] ?? 0);

            if (! isset($grouped[$val])) {
                $grouped[$val] = 0.0;
                $counts[$val] = 0;
            }

            $grouped[$val] += $score;
            $counts[$val]++;
        }

        if (empty($grouped)) {
            return 0.0;
        }

        // Mean score for each parameter value
        $means = [];
        foreach ($grouped as $val => $sum) {
            $means[$val] = $sum / $counts[$val];
        }

        // Variance of means
        $meanOfMeans = array_sum($means) / count($means);
        $variance = 0.0;

        foreach ($means as $mean) {
            $variance += ($mean - $meanOfMeans) ** 2;
        }

        return $variance / count($means);
    }

    /**
     * Compute the variance of mean scores across combinations of two parameters.
     */
    private function jointVariance(string $paramA, string $paramB, string $metric): float
    {
        $cells = [];
        $counts = [];

        foreach ($this->runs as $run) {
            $aVal = $run['params'][$paramA] ?? null;
            $bVal = $run['params'][$paramB] ?? null;
            if ($aVal === null || $bVal === null) {
                continue;
            }

            $key = "{$aVal}|{$bVal}";
            $score = (float) ($run['stats'][$metric] ?? 0);

            if (! isset($cells[$key])) {
                $cells[$key] = 0.0;
                $counts[$key] = 0;
            }

            $cells[$key] += $score;
            $counts[$key]++;
        }

        if (empty($cells)) {
            return 0.0;
        }

        $means = [];
        foreach ($cells as $key => $sum) {
            $means[$key] = $sum / $counts[$key];
        }

        $meanOfMeans = array_sum($means) / count($means);
        $variance = 0.0;

        foreach ($means as $mean) {
            $variance += ($mean - $meanOfMeans) ** 2;
        }

        return $variance / count($means);
    }

    /**
     * Compute stability: average score degradation within 1 step of the best cell.
     *
     * A high stability_score means the optimum is a flat plateau (robust).
     * A low stability_score means the optimum is a sharp peak (fragile).
     *
     * @param  array<int, array<int, float|null>>  $grid
     * @param  array<int, float|int>  $rows
     * @param  array<int, float|int>  $cols
     */
    private function computeStability(array $grid, array $rows, array $cols, float $bestScore): float
    {
        if ($bestScore <= 0) {
            return 0.0;
        }

        // Find the best cell(s)
        $bestCells = [];
        foreach ($rows as $rIdx => $rVal) {
            foreach ($cols as $cIdx => $cVal) {
                $val = $grid[$rIdx][$cIdx] ?? null;
                if ($val !== null && abs($val - $bestScore) < 0.0001) {
                    $bestCells[] = [$rIdx, $cIdx];
                }
            }
        }

        if (empty($bestCells)) {
            return 0.0;
        }

        // Check neighbors of all best cells
        $neighborScores = [];
        foreach ($bestCells as [$rIdx, $cIdx]) {
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dr, $dc]) {
                $nr = $rIdx + $dr;
                $nc = $cIdx + $dc;
                $nVal = $grid[$nr][$nc] ?? null;
                if ($nVal !== null) {
                    $neighborScores[] = $nVal;
                }
            }
        }

        if (empty($neighborScores)) {
            return 0.0;
        }

        // Stability = 1 - (best - mean_neighbor) / (best - worst)
        $meanNeighbor = array_sum($neighborScores) / count($neighborScores);

        return round(max(0.0, 1.0 - ($bestScore - $meanNeighbor) / $bestScore) * 100, 1);
    }

    /**
     * @return array<int, string>
     */
    private function parameterNames(): array
    {
        if (empty($this->runs)) {
            return [];
        }

        return array_keys($this->runs[array_key_first($this->runs)]['params']);
    }
}
