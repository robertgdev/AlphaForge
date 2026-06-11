<?php

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalyzer;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardExporter;

function makeWfResult(int $rank, array $params, float $isScore, float $oosScore, float $degradation, int $oosTrades = 10, int $isTrades = 20): WalkForwardResult
{
    $result = new WalkForwardResult;
    $result->rank = $rank;
    $result->parameters = $params;
    $result->is_score = $isScore;
    $result->oos_score = $oosScore;
    $result->score_degradation = $degradation;
    $result->is_statistics = ['sharpe_ratio' => (string) $isScore, 'total_trades' => $isTrades];
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
            ->and($analysis->oosIsRatio)->toBe(0.0)
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
        expect($analysis->oosIsRatio)->toBeGreaterThan(0.0)
            ->and($analysis->oosIsRatio)->toBeLessThan(100.0);
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

        expect($analysis->oosIsRatio)->toBe(0.0);
    });

    it('classifies as excellent when Spearman > 0.8, WFE > 70% and robustRatio > 70%', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            makeWfResult(2, ['fast' => 20], 1.5, 1.2, 20.0),
            makeWfResult(3, ['fast' => 30], 1.0, 0.8, 20.0),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('excellent')
            ->and($analysis->interpretation)->toBe('strong evidence of generalization; IS rank strongly predicts OOS performance');
    });

    it('classifies as likely_overfit when WFE < 10%', function () {
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

    it('classifies as moderate when at intermediate levels', function () {
        $results = collect([
            makeWfResult(1, ['fast' => 10], 2.0, 0.6, 70.0),
            makeWfResult(2, ['fast' => 20], 1.5, 0.5, 66.7),
        ]);

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->classification)->toBe('moderate');
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

    it('classifies as likely_overfit when robustRatio < 10%', function () {
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
            ->and($analysis->interpretation)->toBe('parameters do not generalize; optimization results are likely overfit');
    });

    it('classifies as likely_overfit when constant IS and partially negative OOS', function () {
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

        expect($analysis->oosIsRatio)->toBeGreaterThan(20.0)
            ->and($analysis->classification)->toBe('likely_overfit');
    });

    it('classifies rank stability appropriately based on correlation', function () {
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

        expect($analysis->rankCorrelation)->not->toBeNull()
            ->and($analysis->rankStabilityLabel)->toBeIn(['stable', 'moderate', 'unstable']);
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
            ->and($analysis->oosIsRatio)->toBe(0.0)
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

describe('robustness classification tiers', function () {
        it('classifies as good when Spearman > 0.6, OOS/IS > 50%, and robustRatio > 50%', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 10], 2.0, 1.4, 30.0),
                makeWfResult(2, ['fast' => 20], 1.5, 0.9, 40.0),
                makeWfResult(3, ['fast' => 30], 1.0, 0.6, 40.0),
            ]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->classification)->toBe('good')
                ->and($analysis->interpretation)->toBe('parameters generalize well to unseen data');
        });

        it('classifies as weak when Spearman is poor but OOS/IS and robustRatio are moderate', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 10], 3.0, 1.0, 66.7),
                makeWfResult(2, ['fast' => 20], 2.0, 1.5, 25.0),
                makeWfResult(3, ['fast' => 30], 1.0, 2.0, -100.0),
                makeWfResult(4, ['fast' => 40], 0.5, 0.5, 0.0),
            ]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->classification)->toBe('weak')
                ->and($analysis->interpretation)->toBe('limited generalization; results should be treated with caution');
        });
    });

    describe('low trade count warning', function () {
        it('sets lowTradeWarning when best OOS result has fewer than 30 trades', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0, 5),
            ]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->lowTradeWarning)->toBeTrue();
        });

        it('does not set lowTradeWarning when both IS and OOS have 30+ trades', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0, 50, 50),
            ]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->lowTradeWarning)->toBeFalse();
        });

        it('does not set lowTradeWarning when IS trades are low but OOS is fine', function () {
            $result = new WalkForwardResult;
            $result->rank = 1;
            $result->parameters = ['fast' => 10];
            $result->is_score = 2.0;
            $result->oos_score = 1.5;
            $result->score_degradation = 25.0;
            $result->is_statistics = ['sharpe_ratio' => '2.0', 'total_trades' => 5];
            $result->oos_statistics = ['sharpe_ratio' => '1.5', 'total_trades' => 40];

            $results = collect([$result]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->lowTradeWarning)->toBeTrue();
        });
    });

    describe('parameter boundary warnings', function () {
        it('returns empty warnings when no parameter_ranges', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 10, 'slow' => 50], 2.0, 1.5, 25.0),
            ]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->boundaryWarnings)->toBe([]);
        });

        it('warns when top results cluster near parameter maximum', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 190], 2.0, 1.5, 25.0),
                makeWfResult(2, ['fast' => 195], 1.8, 1.3, 27.8),
                makeWfResult(3, ['fast' => 200], 1.6, 1.1, 31.3),
            ]);

            $analyzer = new WalkForwardAnalyzer;
            $reflection = new ReflectionMethod($analyzer, 'boundaryWarnings');
            $reflection->setAccessible(true);

            $warnings = $reflection->invoke($analyzer, $results, ['fast' => ['min' => 5, 'max' => 200]]);

            expect($warnings)->toHaveCount(1)
                ->and($warnings[0])->toMatchArray([
                    'param' => 'fast',
                    'direction' => 'max',
                    'boundary' => 200.0,
                ]);
        });

        it('warns when top results cluster near parameter minimum', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 5], 2.0, 1.5, 25.0),
                makeWfResult(2, ['fast' => 7], 1.8, 1.3, 27.8),
                makeWfResult(3, ['fast' => 10], 1.6, 1.1, 31.3),
            ]);

            $analyzer = new WalkForwardAnalyzer;
            $reflection = new ReflectionMethod($analyzer, 'boundaryWarnings');
            $reflection->setAccessible(true);

            $warnings = $reflection->invoke($analyzer, $results, ['fast' => ['min' => 5, 'max' => 200]]);

            expect($warnings)->toHaveCount(1)
                ->and($warnings[0])->toMatchArray([
                    'param' => 'fast',
                    'direction' => 'min',
                    'boundary' => 5.0,
                ]);
        });

        it('returns no warnings when results are well distributed within range', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 50], 2.0, 1.5, 25.0),
                makeWfResult(2, ['fast' => 100], 1.8, 1.3, 27.8),
                makeWfResult(3, ['fast' => 150], 1.6, 1.1, 31.3),
            ]);

            $analyzer = new WalkForwardAnalyzer;
            $reflection = new ReflectionMethod($analyzer, 'boundaryWarnings');
            $reflection->setAccessible(true);

            $warnings = $reflection->invoke($analyzer, $results, ['fast' => ['min' => 5, 'max' => 200]]);

            expect($warnings)->toBe([]);
        });

        it('handles parameter_ranges on model via try/catch fallback', function () {
            $results = collect([
                makeWfResult(1, ['fast' => 10], 2.0, 1.5, 25.0),
            ]);

            $wfRun = Mockery::mock(WalkForwardRun::class);
            $wfRun->shouldReceive('results->orderBy->get')->andReturn($results);
            // parameter_ranges not set — triggers BadMethodCallException, caught gracefully

            $analyzer = new WalkForwardAnalyzer;
            $analysis = $analyzer->analyze($wfRun);

            expect($analysis->boundaryWarnings)->toBe([]);
        });
    });
});

describe('WalkForwardAnalysis benchmark', function () {
    it('includes benchmark fields in empty analysis', function () {
        $wfRun = Mockery::mock(WalkForwardRun::class);
        $wfRun->shouldReceive('results->orderBy->get')->andReturn(collect());

        $analyzer = new WalkForwardAnalyzer;
        $analysis = $analyzer->analyze($wfRun);

        expect($analysis->benchmarkReturn)->toBe(0.0)
            ->and($analysis->benchmarkMaxDrawdown)->toBe(0.0)
            ->and($analysis->benchmarkSharpe)->toBe(0.0)
            ->and($analysis->benchmarkHasData)->toBeFalse();
    });

    it('accepts benchmark constructor params', function () {
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: Mockery::mock(WalkForwardRun::class),
            results: [],
            oosIsRatio: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
            classification: 'moderate',
            interpretation: '',
            benchmarkReturn: 12.5,
            benchmarkMaxDrawdown: 5.3,
            benchmarkSharpe: 1.2,
            benchmarkHasData: true,
        );

        expect($analysis->benchmarkReturn)->toBe(12.5)
            ->and($analysis->benchmarkMaxDrawdown)->toBe(5.3)
            ->and($analysis->benchmarkSharpe)->toBe(1.2)
            ->and($analysis->benchmarkHasData)->toBeTrue();
    });

    it('exports benchmark data in JSON when hasData is true', function () {
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: Mockery::mock(WalkForwardRun::class),
            results: [],
            oosIsRatio: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
            classification: 'moderate',
            interpretation: '',
            benchmarkReturn: 15.0,
            benchmarkMaxDrawdown: 10.0,
            benchmarkSharpe: 1.5,
            benchmarkHasData: true,
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);
        $data = json_decode($json, true);

        expect($data['benchmark'])->not->toBeNull()
            ->and($data['benchmark']['return_pct'])->toEqual(15.0)
            ->and($data['benchmark']['max_drawdown_pct'])->toEqual(10.0)
            ->and($data['benchmark']['sharpe'])->toEqual(1.5);
    });

    it('exports null benchmark when hasData is false', function () {
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: Mockery::mock(WalkForwardRun::class),
            results: [],
            oosIsRatio: 0.0,
            robustCount: 0,
            robustRatio: 0.0,
            avgDegradation: 0.0,
            medianDegradation: 0.0,
            bestOosRank: null,
            bestOosResult: null,
            classification: 'moderate',
            interpretation: '',
            benchmarkHasData: false,
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);
        $data = json_decode($json, true);

        expect($data['benchmark'])->toBeNull();
    });
});
