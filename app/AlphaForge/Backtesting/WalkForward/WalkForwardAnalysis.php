<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;

readonly class WalkForwardAnalysis
{
    /**
     * @param  WalkForwardResult[]  $results
     * @param  array<int, array{param: string, direction: string, boundary: float, pct: float}>  $boundaryWarnings
     */
    public function __construct(
        public WalkForwardRun $walkForwardRun,
        public array $results,
        public float $oosIsRatio,
        public int $robustCount,
        public float $robustRatio,
        public int $beatBuyHoldCount,
        public float $beatBuyHoldRatio,
        public int $returnGt10Count,
        public float $returnGt10Ratio,
        public int $sharpeBeatBenchmarkCount,
        public float $sharpeBeatBenchmarkRatio,
        public float $medianIsScore,
        public float $medianOosScore,
        public float $avgDegradation,
        public float $medianDegradation,
        public ?int $bestOosRank,
        public ?WalkForwardResult $bestOosResult,
        public bool $oosIsRatioWarning = false,
        public string $stabilityClassification = 'moderate',
        public string $stabilityInterpretation = '',
        public string $economicPerformance = 'moderate',
        public string $economicInterpretation = '',
        public ?float $rankCorrelation = null,
        public string $rankStabilityLabel = 'unstable',
        public int $reliableCount = 0,
        public float $reliableRatio = 0.0,
        public int $minTrades = 0,
        public array $boundaryWarnings = [],
        public bool $lowTradeWarning = false,
        public bool $suspiciousSharpe = false,
        public float $benchmarkReturn = 0.0,
        public float $benchmarkMaxDrawdown = 0.0,
        public float $benchmarkSharpe = 0.0,
        public bool $benchmarkHasData = false,
        public float $timeInMarket = 0.0,
        public float $exposureAdjustedTarget = 0.0,
        public float $captureRatio = 0.0,
    ) {}
}