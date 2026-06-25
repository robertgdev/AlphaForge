<?php

namespace App\AlphaForge\Backtesting\Optimization\Sensitivity;

use RobertGDev\AlphaforgeStatistics\Sensitivity\ParameterSensitivityService as PackageParameterSensitivityService;
use App\AlphaForge\Backtesting\Model\BacktestRun;

class ParameterSensitivityService
{
    private PackageParameterSensitivityService $packageService;

    /**
     * @param  list<array{params: array<string, mixed>, stats: array<string, mixed>}>  $runs
     */
    public function __construct(array $runs)
    {
        $this->packageService = new PackageParameterSensitivityService($runs);
    }

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

    public function importance(string $metric = 'optimization_score'): array
    {
        return $this->packageService->importance($metric);
    }

    public function surface(string $paramA, string $paramB, string $metric = 'optimization_score'): array
    {
        return $this->packageService->surface($paramA, $paramB, $metric);
    }

    public function interactions(string $metric = 'optimization_score'): array
    {
        return $this->packageService->interactions($metric);
    }
}