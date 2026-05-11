<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;

readonly class WalkForwardAnalysis
{
    /**
     * @param  WalkForwardResult[]  $results
     */
    public function __construct(
        public WalkForwardRun $walkForwardRun,
        public array $results,
        public float $walkForwardEfficiency,
        public int $robustCount,
        public float $robustRatio,
        public float $avgDegradation,
        public float $medianDegradation,
        public ?int $bestOosRank,
        public ?WalkForwardResult $bestOosResult,
        public string $classification = 'marginal',
        public string $interpretation = '',
        public ?float $rankCorrelation = null,
        public string $rankStabilityLabel = 'unstable',
        public int $reliableCount = 0,
        public float $reliableRatio = 0.0,
        public int $minTrades = 0,
    ) {}
}
