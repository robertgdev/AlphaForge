<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Backtesting\Optimization\Objective\ObjectiveFunctionInterface;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use Safe\DateTimeImmutable;

class OptimizationConfig
{
    public OptimizationMethod $method = OptimizationMethod::RANDOM;

    public ?int $iterations = null;

    public ?int $populationSize = null;

    public ?int $generations = null;

    public ?float $mutationRate = null;

    public ?float $crossoverRate = null;

    public string|ObjectiveFunctionInterface $objective = 'sharpe_ratio';

    public int $topN = 50;

    /** @var array<string, mixed>|null */
    public ?array $parameterOverrides = null;

    public string $strategyAlias;

    /** @var array<string> */
    public array $symbols;

    public TimeframeEnum $timeframe;

    public string $exchange;

    public string $initialCapital;

    public string $stakeCurrency;

    /** @var array<string, mixed> */
    public array $commissionConfig = [];

    public ?DateTimeImmutable $startDate = null;

    public ?DateTimeImmutable $endDate = null;

    public static function fromArray(array $data): self
    {
        $config = new self;

        $config->strategyAlias = $data['strategy_alias'] ?? $data['strategyAlias'];
        $config->symbols = $data['symbols'];
        $config->timeframe = $data['timeframe'] instanceof TimeframeEnum
            ? $data['timeframe']
            : TimeframeEnum::from($data['timeframe']);
        $config->exchange = $data['exchange'];
        $config->initialCapital = (string) ($data['initial_capital'] ?? $data['initialCapital'] ?? '10000');
        $config->stakeCurrency = $data['stake_currency'] ?? $data['stakeCurrency'] ?? 'USDT';
        $config->commissionConfig = $data['commission_config'] ?? $data['commissionConfig'] ?? [];

        if (isset($data['start_date']) || isset($data['startDate'])) {
            $sd = $data['start_date'] ?? $data['startDate'];
            $config->startDate = $sd instanceof DateTimeImmutable
                ? $sd
                : ($sd !== null ? new DateTimeImmutable($sd) : null);
        }

        if (isset($data['end_date']) || isset($data['endDate'])) {
            $ed = $data['end_date'] ?? $data['endDate'];
            $config->endDate = $ed instanceof DateTimeImmutable
                ? $ed
                : ($ed !== null ? new DateTimeImmutable($ed) : null);
        }

        if (isset($data['method'])) {
            $config->method = $data['method'] instanceof OptimizationMethod
                ? $data['method']
                : OptimizationMethod::from($data['method']);
        }

        $config->iterations = $data['iterations'] ?? null;
        $config->populationSize = $data['population_size'] ?? $data['populationSize'] ?? null;
        $config->generations = $data['generations'] ?? null;
        $config->mutationRate = $data['mutation_rate'] ?? $data['mutationRate'] ?? null;
        $config->crossoverRate = $data['crossover_rate'] ?? $data['crossoverRate'] ?? null;

        $config->objective = $data['objective'] ?? 'sharpe_ratio';
        $config->topN = $data['top_n'] ?? $data['topN'] ?? 50;
        $config->parameterOverrides = $data['parameter_overrides'] ?? $data['parameterOverrides'] ?? null;

        return $config;
    }
}
