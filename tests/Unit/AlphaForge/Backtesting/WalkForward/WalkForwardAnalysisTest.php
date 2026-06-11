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
            oosIsRatio: 55.0,
            robustCount: 8,
            robustRatio: 0.8,
            beatBuyHoldCount: 1,
            beatBuyHoldRatio: 0.1,
            returnGt10Count: 2,
            returnGt10Ratio: 0.2,
            sharpeBeatBenchmarkCount: 5,
            sharpeBeatBenchmarkRatio: 0.5,
            medianIsScore: 3.5,
            medianOosScore: 2.8,
            avgDegradation: 35.0,
            medianDegradation: 30.0,
            bestOosRank: 2,
            bestOosResult: $bestResult,
            oosIsRatioWarning: false,
            stabilityClassification: 'good',
            stabilityInterpretation: 'parameters generalize well to unseen data',
            economicPerformance: 'moderate',
            economicInterpretation: 'strategy captures some market move',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
            reliableCount: 7,
            reliableRatio: 0.7,
            minTrades: 10,
            suspiciousSharpe: false,
            timeInMarket: 55.66,
            exposureAdjustedTarget: 27.44,
            captureRatio: 3.4,
        );

        expect($analysis->walkForwardRun)->toBe($wfRun)
            ->and($analysis->results)->toBe([$bestResult])
            ->and($analysis->oosIsRatio)->toBe(55.0)
            ->and($analysis->robustCount)->toBe(8)
            ->and($analysis->robustRatio)->toBe(0.8)
            ->and($analysis->beatBuyHoldCount)->toBe(1)
            ->and($analysis->beatBuyHoldRatio)->toBe(0.1)
            ->and($analysis->returnGt10Count)->toBe(2)
            ->and($analysis->returnGt10Ratio)->toBe(0.2)
            ->and($analysis->sharpeBeatBenchmarkCount)->toBe(5)
            ->and($analysis->sharpeBeatBenchmarkRatio)->toBe(0.5)
            ->and($analysis->medianIsScore)->toBe(3.5)
            ->and($analysis->medianOosScore)->toBe(2.8)
            ->and($analysis->avgDegradation)->toBe(35.0)
            ->and($analysis->medianDegradation)->toBe(30.0)
            ->and($analysis->bestOosRank)->toBe(2)
            ->and($analysis->bestOosResult)->toBe($bestResult)
            ->and($analysis->stabilityClassification)->toBe('good')
            ->and($analysis->stabilityInterpretation)->toBe('parameters generalize well to unseen data')
            ->and($analysis->economicPerformance)->toBe('moderate')
            ->and($analysis->economicInterpretation)->toBe('strategy captures some market move')
            ->and($analysis->rankCorrelation)->toBe(0.85)
            ->and($analysis->rankStabilityLabel)->toBe('stable')
            ->and($analysis->reliableCount)->toBe(7)
            ->and($analysis->reliableRatio)->toBe(0.7)
            ->and($analysis->minTrades)->toBe(10)
            ->and($analysis->suspiciousSharpe)->toBeFalse()
            ->and($analysis->oosIsRatioWarning)->toBeFalse()
            ->and($analysis->timeInMarket)->toBe(55.66)
            ->and($analysis->exposureAdjustedTarget)->toBe(27.44)
            ->and($analysis->captureRatio)->toBe(3.4);
    });

    it('allows null best OOS fields', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);

        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [],
            oosIsRatio: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
        );

        expect($analysis->bestOosRank)->toBeNull()
            ->and($analysis->bestOosResult)->toBeNull();
    });

    it('supports economic performance and suspicious sharpe fields', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);

        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [],
            oosIsRatio: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
            economicPerformance: 'poor',
            economicInterpretation: 'strategy return is negligible',
            suspiciousSharpe: true,
            oosIsRatioWarning: true,
        );

        expect($analysis->economicPerformance)->toBe('poor')
            ->and($analysis->economicInterpretation)->toBe('strategy return is negligible')
            ->and($analysis->suspiciousSharpe)->toBeTrue()
            ->and($analysis->oosIsRatioWarning)->toBeTrue();
    });

    it('defaults new fields', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);

        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [],
            oosIsRatio: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
        );

        expect($analysis->stabilityClassification)->toBe('moderate')
            ->and($analysis->stabilityInterpretation)->toBe('')
            ->and($analysis->economicPerformance)->toBe('moderate')
            ->and($analysis->economicInterpretation)->toBe('')
            ->and($analysis->rankCorrelation)->toBeNull()
            ->and($analysis->rankStabilityLabel)->toBe('unstable')
            ->and($analysis->reliableCount)->toBe(0)
            ->and($analysis->reliableRatio)->toBe(0.0)
            ->and($analysis->minTrades)->toBe(0)
            ->and($analysis->suspiciousSharpe)->toBeFalse()
            ->and($analysis->oosIsRatioWarning)->toBeFalse();
    });
});
