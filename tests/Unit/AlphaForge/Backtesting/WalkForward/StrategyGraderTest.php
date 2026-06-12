<?php

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\WalkForward\StrategyGrader;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;

function makeAnalysisForGrading(
    float $medianOosReturn = 5.0,
    float $benchmarkReturn = 20.0,
    int $beatBuyHoldCount = 2,
    bool $benchmarkHasData = true,
    float $captureRatio = 15.0,
    float $robustRatio = 0.6,
    float $rankCorrelation = 0.5,
    string $stabilityClassification = 'good',
    float $medianOosMaxDd = 15.0,
    float $medianOosSharpe = 0.8,
    float $benchmarkSharpe = 1.0,
    bool $suspiciousSharpe = false,
    float $medianDegradation = 15.0,
    array $boundaryWarnings = [],
    float $timeInMarket = 50.0,
): WalkForwardAnalysis {
    return new WalkForwardAnalysis(
        walkForwardRun: Mockery::mock(WalkForwardRun::class),
        results: [],
        oosIsRatio: 60.0,
        robustCount: 3,
        robustRatio: $robustRatio,
        beatBuyHoldCount: $beatBuyHoldCount,
        beatBuyHoldRatio: 0.4,
        returnGt10Count: 1,
        returnGt10Ratio: 0.2,
        sharpeBeatBenchmarkCount: 1,
        sharpeBeatBenchmarkRatio: 0.2,
        medianIsScore: 2.0,
        medianOosScore: 1.5,
        avgDegradation: 20.0,
        medianDegradation: $medianDegradation,
        bestOosRank: 1,
        bestOosResult: null,
        stabilityClassification: $stabilityClassification,
        stabilityInterpretation: 'parameters generalize well',
        rankCorrelation: $rankCorrelation,
        rankStabilityLabel: 'moderate',
        reliableCount: 2,
        reliableRatio: 0.4,
        minTrades: 10,
        boundaryWarnings: $boundaryWarnings,
        lowTradeWarning: false,
        suspiciousSharpe: $suspiciousSharpe,
        benchmarkReturn: $benchmarkReturn,
        benchmarkMaxDrawdown: 10.0,
        benchmarkSharpe: $benchmarkSharpe,
        benchmarkHasData: $benchmarkHasData,
        timeInMarket: $timeInMarket,
        exposureAdjustedTarget: 10.0,
        captureRatio: $captureRatio,
        marketCapture: $benchmarkReturn > 0 ? ($medianOosReturn / $benchmarkReturn) * 100 : 0.0,
        capitalEfficiency: 0.0,
        medianOosReturn: $medianOosReturn,
        medianOosSharpe: $medianOosSharpe,
        medianOosMaxDd: $medianOosMaxDd,
    );
}

describe('StrategyGrader', function () {
    describe('gated scoring', function () {
        it('caps at 1 star (score ≤ 19) when OOS return <= 0', function () {
            $analysis = makeAnalysisForGrading(
                medianOosReturn: -1.0,
                benchmarkReturn: 20.0,
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['score'])->toBeLessThanOrEqual(19.0)
                ->and($grade['stars'])->toBe('★☆☆☆☆');
        });

        it('caps at 2 stars (score ≤ 39) when OOS return < 25% of buy-and-hold', function () {
            $analysis = makeAnalysisForGrading(
                medianOosReturn: 2.0,
                benchmarkReturn: 20.0,
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['score'])->toBeLessThanOrEqual(39.0)
                ->and(in_array($grade['stars'], ['★☆☆☆☆', '★★☆☆☆'], true))->toBeTrue();
        });

        it('caps at 3 stars (score ≤ 59) when beatBuyHoldCount is 0', function () {
            $analysis = makeAnalysisForGrading(
                medianOosReturn: 10.0,
                benchmarkReturn: 20.0,
                beatBuyHoldCount: 0,
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['score'])->toBeLessThanOrEqual(59.0)
                ->and(in_array($grade['stars'], ['★☆☆☆☆', '★★☆☆☆', '★★★☆☆'], true))->toBeTrue();
        });

        it('allows full 5 stars when all gates pass', function () {
            $analysis = makeAnalysisForGrading(
                medianOosReturn: 15.0,
                benchmarkReturn: 20.0,
                beatBuyHoldCount: 3,
                captureRatio: 60.0,
                robustRatio: 0.9,
                rankCorrelation: 0.9,
                stabilityClassification: 'excellent',
                medianOosMaxDd: 5.0,
                medianOosSharpe: 1.5,
                benchmarkSharpe: 1.0,
                suspiciousSharpe: false,
                medianDegradation: 5.0,
                boundaryWarnings: [],
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['score'])->toBeGreaterThanOrEqual(80.0)
                ->and($grade['stars'])->toBe('★★★★★');
        });

        it('applies no gate when no benchmark data', function () {
            $analysis = makeAnalysisForGrading(
                benchmarkHasData: false,
                benchmarkReturn: 0.0,
                beatBuyHoldCount: 0,
                captureRatio: 0.0,
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['score'])->toBeGreaterThan(0);
        });
    });

    describe('component grading', function () {
        it('grades economic based on capture ratio', function () {
            $analysis = makeAnalysisForGrading(captureRatio: 60.0);

            $grade = StrategyGrader::grade($analysis);

            expect($grade['breakdown']['economic'])->toBe(100.0);
        });

        it('grades economic as 0 when capture ratio <= 0', function () {
            $analysis = makeAnalysisForGrading(captureRatio: 0.0);

            $grade = StrategyGrader::grade($analysis);

            expect($grade['breakdown']['economic'])->toBe(0.0);
        });

        it('applies penalty for boundary warnings in optimization', function () {
            $analysis = makeAnalysisForGrading(
                boundaryWarnings: [
                    ['param' => 'fast', 'direction' => 'max', 'boundary' => 200.0, 'pct' => 60.0],
                    ['param' => 'slow', 'direction' => 'min', 'boundary' => 5.0, 'pct' => 70.0],
                ],
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['breakdown']['optimization'])->toBeLessThanOrEqual(60);
        });
    });

    describe('label and star logic', function () {
        it('returns 5-star label when score >= 80', function () {
            $analysis = makeAnalysisForGrading(
                medianOosReturn: 15.0,
                benchmarkReturn: 20.0,
                beatBuyHoldCount: 3,
                captureRatio: 60.0,
                robustRatio: 0.9,
                rankCorrelation: 0.9,
                stabilityClassification: 'excellent',
                medianOosMaxDd: 5.0,
                medianOosSharpe: 1.5,
                benchmarkSharpe: 1.0,
                suspiciousSharpe: false,
                medianDegradation: 5.0,
                boundaryWarnings: [],
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['stars'])->toBe('★★★★★')
                ->and($grade['label'])->toBe('(5/5) Exceptional across performance, robustness, and risk');
        });

        it('returns 1-star label when score < 20', function () {
            $analysis = makeAnalysisForGrading(
                medianOosReturn: -1.0,
                benchmarkReturn: 20.0,
            );

            $grade = StrategyGrader::grade($analysis);

            expect($grade['stars'])->toBe('★☆☆☆☆')
                ->and($grade['label'])->toBe('(1/5) Poor; likely unusable');
        });
    });
});