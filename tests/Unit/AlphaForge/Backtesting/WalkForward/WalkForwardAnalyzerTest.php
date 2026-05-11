<?php

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalyzer;

function makeWfResult(int $rank, array $params, float $isScore, float $oosScore, float $degradation, int $oosTrades = 10): WalkForwardResult
{
    $result = new WalkForwardResult;
    $result->rank = $rank;
    $result->parameters = $params;
    $result->is_score = $isScore;
    $result->oos_score = $oosScore;
    $result->score_degradation = $degradation;
    $result->is_statistics = ['sharpe_ratio' => (string) $isScore, 'total_trades' => 20];
    $result->oos_statistics = ['sharpe_ratio' => (string) $oosScore, 'total_trades' => $oosTrades];

    return $result;
}

describe('WalkForwardAnalyzer', function () {
    it('returns empty analysis for run with no results', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn(collect());

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis)->toBeInstanceOf(WalkForwardAnalysis::class)
            ->and($analysis->results)->toBe([])
            ->and($analysis->walkForwardEfficiency)->toBe(0.0)
            ->and($analysis->robustCount)->toBe(0)
            ->and($analysis->robustRatio)->toBe(0.0)
            ->and($analysis->avgDegradation)->toBe(0.0)
            ->and($analysis->medianDegradation)->toBe(0.0)
            ->and($analysis->bestOosRank)->toBeNull()
            ->and($analysis->bestOosResult)->toBeNull()
            ->and($analysis->classification)->toBe('likely_overfit')
            ->and($analysis->rankCorrelation)->toBeNull()
            ->and($analysis->reliableCount)->toBe(0);
    });

    it('computes walk-forward efficiency', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        $expectedWfe = ((1.5 + 1.2) / (2.0 + 1.5)) * 100;
        expect($analysis->walkForwardEfficiency)->toBeGreaterThan(0.0)
            ->and($analysis->walkForwardEfficiency)->toBeLessThan(100.0);
    });

    it('counts robust results (positive OOS score)', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            makeWfResult(2, ['fast' => 20], 1.5, -0.5, 133.3),
            makeWfResult(3, ['fast' => 30], 1.0, 0.8, 20.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->robustCount)->toBe(2)
            ->and($analysis->robustRatio)->toBe(2 / 3);
    });

    it('computes average degradation', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            makeWfResult(2, ['fast' => 20], 2.0, 1.0, 50.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->avgDegradation)->toBe(37.5);
    });

    it('computes median degradation', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            makeWfResult(2, ['fast' => 20], 2.0, 1.0, 50.0),
            makeWfResult(3, ['fast' => 30], 2.0, 0.6, 70.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->medianDegradation)->toBe(50.0);
    });

    it('identifies best OOS result', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.0, 50.0),
            makeWfResult(2, ['fast' => 20], 1.5, 1.8, -20.0),
            makeWfResult(3, ['fast' => 30], 1.0, 0.5, 50.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->bestOosRank)->toBe(2)
            ->and($analysis->bestOosResult->oos_score)->toBe(1.8);
    });

    it('handles zero IS score gracefully for WFE', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 0.0, 1.5, 0.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->walkForwardEfficiency)->toBe(0.0);
    });

    it('classifies as robust when WFE > 50% and robustRatio > 50%', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0),
            makeWfResult(3, ['fast' => 30], 1.0, 0.8, 20.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('robust')
            ->and($analysis->interpretation)->toBe('parameters generalize well to unseen data');
    });

    it('classifies as likely_overfit when WFE < 20%', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 0.1, 95.0),
            makeWfResult(2, ['fast' => 20], 1.5, -0.5, 133.3),
            makeWfResult(3, ['fast' => 30], 1.0, -0.3, 130.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('likely_overfit')
            ->and($analysis->interpretation)->toBe('parameters do not generalize; optimization results are likely overfit');
    });

    it('classifies as marginal between robust and overfit', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 0.6, 70.0),
            makeWfResult(2, ['fast' => 20], 1.5, 0.5, 66.7),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('marginal');
    });

    it('computes Spearman rank correlation', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.8, 10.0),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0),
            makeWfResult(3, ['fast' => 30], 1.0, 0.8, 20.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->rankCorrelation)->not->toBeNull()
            ->and($analysis->rankCorrelation)->toBeGreaterThan(0.0)
            ->and($analysis->rankStabilityLabel)->toBeIn(['stable', 'moderate', 'unstable']);
    });

    it('returns null rank correlation for single result', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->rankCorrelation)->toBeNull()
            ->and($analysis->rankStabilityLabel)->toBe('unstable');
    });

    it('returns 0 rank correlation when all OOS scores identical', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.0, 50.0),
            makeWfResult(2, ['fast' => 20], 1.5, 1.0, 33.3),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->rankCorrelation)->toBe(0.0);
    });

    it('counts reliable results with minTrades filter', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0, 15),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0, 5),
            makeWfResult(3, ['fast' => 30], 1.0, -0.3, 130.0, 20),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun, minTrades: 10);

        expect($analysis->reliableCount)->toBe(1)
            ->and($analysis->reliableRatio)->toBe(1 / 3)
            ->and($analysis->minTrades)->toBe(10);
    });

    it('has zero reliable count when minTrades is 0', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0, 15),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun, minTrades: 0);

        expect($analysis->reliableCount)->toBe(0)
            ->and($analysis->minTrades)->toBe(0);
    });
});
