<?php

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;

describe('WalkForwardAnalysis', function () {
    it('creates with all properties', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);
        $bestResult = Mockery::mock(WalkForwardResult::class);

        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$bestResult],
            walkForwardEfficiency: 55.0,
            robustCount: 8,
            robustRatio: 0.8,
            avgDegradation: 35.0,
            medianDegradation: 30.0,
            bestOosRank: 2,
            bestOosResult: $bestResult,
            classification: 'robust',
            interpretation: 'parameters generalize well to unseen data',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
            reliableCount: 7,
            reliableRatio: 0.7,
            minTrades: 10,
        );

        expect($analysis->walkForwardRun)->toBe($wfRun)
            ->and($analysis->results)->toBe([$bestResult])
            ->and($analysis->walkForwardEfficiency)->toBe(55.0)
            ->and($analysis->robustCount)->toBe(8)
            ->and($analysis->robustRatio)->toBe(0.8)
            ->and($analysis->avgDegradation)->toBe(35.0)
            ->and($analysis->medianDegradation)->toBe(30.0)
            ->and($analysis->bestOosRank)->toBe(2)
            ->and($analysis->bestOosResult)->toBe($bestResult)
            ->and($analysis->classification)->toBe('robust')
            ->and($analysis->interpretation)->toBe('parameters generalize well to unseen data')
            ->and($analysis->rankCorrelation)->toBe(0.85)
            ->and($analysis->rankStabilityLabel)->toBe('stable')
            ->and($analysis->reliableCount)->toBe(7)
            ->and($analysis->reliableRatio)->toBe(0.7)
            ->and($analysis->minTrades)->toBe(10);
    });

    it('allows null best OOS fields', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);

        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [],
            walkForwardEfficiency: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
        );

        expect($analysis->bestOosRank)->toBeNull()
            ->and($analysis->bestOosResult)->toBeNull();
    });

    it('defaults new fields', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);

        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [],
            walkForwardEfficiency: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
        );

        expect($analysis->classification)->toBe('marginal')
            ->and($analysis->rankCorrelation)->toBeNull()
            ->and($analysis->rankStabilityLabel)->toBe('unstable')
            ->and($analysis->reliableCount)->toBe(0)
            ->and($analysis->reliableRatio)->toBe(0.0)
            ->and($analysis->minTrades)->toBe(0);
    });
});
