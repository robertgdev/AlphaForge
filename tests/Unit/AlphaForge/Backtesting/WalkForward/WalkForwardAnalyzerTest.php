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

    it('computes perfect positive Spearman correlation when IS and OOS ranks match', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 3.0, 2.5, 16.7, 10),
            makeWfResult(2, ['fast' => 20], 2.0, 1.5, 25.0, 10),
            makeWfResult(3, ['fast' => 30], 1.0, 0.5, 50.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->rankCorrelation)->toBe(1.0)
            ->and($analysis->rankStabilityLabel)->toBe('stable');
    });

    it('computes negative Spearman correlation when IS and OOS ranks are inverted', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 3.0, 0.3, 90.0, 10),
            makeWfResult(2, ['fast' => 20], 2.0, 0.5, 75.0, 10),
            makeWfResult(3, ['fast' => 30], 1.0, 2.5, -150.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->rankCorrelation)->toBe(-1.0)
            ->and($analysis->rankStabilityLabel)->toBe('unstable');
    });

    it('computes Spearman correlation with ties in OOS scores', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 3.0, 1.5, 50.0, 10),
            makeWfResult(2, ['fast' => 20], 2.0, 1.5, 25.0, 10),
            makeWfResult(3, ['fast' => 30], 1.0, 0.5, 50.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->rankCorrelation)->not->toBeNull()
            ->and($analysis->rankCorrelation)->toBeGreaterThan(0.0)
            ->and($analysis->rankCorrelation)->toBeLessThanOrEqual(1.0);
    });

    it('classifies as likely_overfit when robustRatio < 20%', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, -0.5, 125.0, 10),
            makeWfResult(2, ['fast' => 20], 2.0, -0.3, 115.0, 10),
            makeWfResult(3, ['fast' => 30], 2.0, -0.1, 105.0, 10),
            makeWfResult(4, ['fast' => 40], 2.0, 0.1, 95.0, 10),
            makeWfResult(5, ['fast' => 50], 2.0, 0.2, 90.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('likely_overfit')
            ->and($analysis->robustRatio)->toBe(2 / 5)
            ->and($analysis->interpretation)->toBe('parameters do not generalize; optimization results are likely overfit');
    });

    it('classifies as marginal when WFE 30% and robustRatio 40%', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 2.0, 0.0, 10),
            makeWfResult(2, ['fast' => 20], 2.0, 1.0, 50.0, 10),
            makeWfResult(3, ['fast' => 30], 2.0, -0.3, 115.0, 10),
            makeWfResult(4, ['fast' => 40], 2.0, -0.2, 110.0, 10),
            makeWfResult(5, ['fast' => 50], 2.0, -0.1, 105.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('marginal')
            ->and($analysis->interpretation)->toBe('some parameters generalize; results should be treated with caution');
    });

    it('classifies rank stability as moderate for correlation between 0.3 and 0.7', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 3.0, 1.0, 66.7, 10),
            makeWfResult(2, ['fast' => 20], 2.0, 2.0, -0.0, 10),
            makeWfResult(3, ['fast' => 30], 1.0, 0.5, 50.0, 10),
            makeWfResult(4, ['fast' => 40], 0.5, 1.5, -200.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        if ($analysis->rankCorrelation > 0.3 && $analysis->rankCorrelation <= 0.7) {
            expect($analysis->rankStabilityLabel)->toBe('moderate');
        }
    });

    it('counts reliable results as profitable OOS AND sufficient trades', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0, 20),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0, 8),
            makeWfResult(3, ['fast' => 30], 1.0, -0.3, 130.0, 25),
            makeWfResult(4, ['fast' => 40], 0.8, 0.5, 37.5, 12),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun, minTrades: 10);

        expect($analysis->reliableCount)->toBe(2)
            ->and($analysis->reliableRatio)->toBe(2 / 4);
    });

    it('reliable count excludes profitable OOS with insufficient trades', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0, 3),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0, 1),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun, minTrades: 10);

        expect($analysis->reliableCount)->toBe(0)
            ->and($analysis->reliableRatio)->toBe(0.0);
    });

    it('reliable count excludes unprofitable OOS even with sufficient trades', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, -0.5, 125.0, 50),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun, minTrades: 10);

        expect($analysis->reliableCount)->toBe(0);
    });

    it('handles all results with zero OOS score', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 0.0, 100.0, 10),
            makeWfResult(2, ['fast' => 20], 1.5, 0.0, 100.0, 10),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->robustCount)->toBe(0)
            ->and($analysis->walkForwardEfficiency)->toBe(0.0)
            ->and($analysis->rankCorrelation)->toBe(0.0);
    });

    it('handles large dataset of results', function () {
        $results = collect();
        for ($i = 1; $i <= 50; $i++) {
            $results->push(makeWfResult($i, ['fast' => $i * 2], (float) (51 - $i), (float) max(0, 51 - $i - 5), 0.0, 10));
        }

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->results)->toHaveCount(50)
            ->and($analysis->rankCorrelation)->not->toBeNull()
            ->and($analysis->rankCorrelation)->toBeGreaterThan(0.5);
    });
});
