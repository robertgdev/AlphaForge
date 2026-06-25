<?php

namespace App\AlphaForge\Backtesting\WalkForward;

use RobertGDev\AlphaforgeStatistics\WalkForward\StrategyGrader as PackageStrategyGrader;

final readonly class StrategyGrader
{
    public static function grade(WalkForwardAnalysis $analysis): array
    {
        $packageAnalysis = new \RobertGDev\AlphaforgeStatistics\WalkForward\WalkForwardAnalysis(
            results: [],
            oosIsRatio: $analysis->oosIsRatio,
            robustCount: $analysis->robustCount,
            robustRatio: $analysis->robustRatio,
            beatBuyHoldCount: $analysis->beatBuyHoldCount,
            beatBuyHoldRatio: $analysis->beatBuyHoldRatio,
            returnGt10Count: $analysis->returnGt10Count,
            returnGt10Ratio: $analysis->returnGt10Ratio,
            sharpeBeatBenchmarkCount: $analysis->sharpeBeatBenchmarkCount,
            sharpeBeatBenchmarkRatio: $analysis->sharpeBeatBenchmarkRatio,
            medianIsScore: $analysis->medianIsScore,
            medianOosScore: $analysis->medianOosScore,
            avgDegradation: $analysis->avgDegradation,
            medianDegradation: $analysis->medianDegradation,
            bestOosRank: $analysis->bestOosRank,
            oosIsRatioWarning: $analysis->oosIsRatioWarning,
            stabilityClassification: $analysis->stabilityClassification,
            stabilityInterpretation: $analysis->stabilityInterpretation,
            economicPerformance: $analysis->economicPerformance,
            economicInterpretation: $analysis->economicInterpretation,
            rankCorrelation: $analysis->rankCorrelation,
            rankStabilityLabel: $analysis->rankStabilityLabel,
            reliableCount: $analysis->reliableCount,
            reliableRatio: $analysis->reliableRatio,
            minTrades: $analysis->minTrades,
            boundaryWarnings: $analysis->boundaryWarnings,
            lowTradeWarning: $analysis->lowTradeWarning,
            suspiciousSharpe: $analysis->suspiciousSharpe,
            benchmarkReturn: $analysis->benchmarkReturn,
            benchmarkMaxDrawdown: $analysis->benchmarkMaxDrawdown,
            benchmarkSharpe: $analysis->benchmarkSharpe,
            benchmarkHasData: $analysis->benchmarkHasData,
            timeInMarket: $analysis->timeInMarket,
            exposureAdjustedTarget: $analysis->exposureAdjustedTarget,
            captureRatio: $analysis->captureRatio,
            marketCapture: $analysis->marketCapture,
            capitalEfficiency: $analysis->capitalEfficiency,
            medianOosReturn: $analysis->medianOosReturn,
            medianOosSharpe: $analysis->medianOosSharpe,
            medianOosMaxDd: $analysis->medianOosMaxDd,
        );

        return PackageStrategyGrader::grade($packageAnalysis);
    }
}