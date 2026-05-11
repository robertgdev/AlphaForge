<?php

use App\AlphaForge\Backtesting\Model\WalkForwardResult;
use App\AlphaForge\Backtesting\Model\WalkForwardRun;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalysis;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardExporter;

describe('WalkForwardExporter', function () {
    it('exports empty analysis as empty CSV', function () {
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

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        expect($csv)->toBe('');
    });

    it('exports results as CSV with dynamic parameter columns', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['fastPeriod' => 10, 'slowPeriod' => 50];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_return_percent' => 10.0, 'max_drawdown_percent' => 0.05, 'total_trades' => 20, 'win_rate' => 0.6];
        $result1->oos_statistics = ['total_return_percent' => 5.0, 'max_drawdown_percent' => 0.08, 'total_trades' => 15, 'win_rate' => 0.5];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            walkForwardEfficiency: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        expect($csv)->toContain('rank')
            ->and($csv)->toContain('param_fastPeriod')
            ->and($csv)->toContain('param_slowPeriod')
            ->and($csv)->toContain('is_score')
            ->and($csv)->toContain('oos_score')
            ->and($csv)->toContain('10')
            ->and($csv)->toContain('50');
    });

    it('exports results as JSON', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['fastPeriod' => 10];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['sharpe_ratio' => 2.0];
        $result1->oos_statistics = ['sharpe_ratio' => 1.5];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            walkForwardEfficiency: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
            classification: 'robust',
            interpretation: 'parameters generalize well to unseen data',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);

        $data = json_decode($json, true);
        expect($data)->not->toBeNull()
            ->and($data['classification'])->toBe('robust')
            ->and((float) $data['walk_forward_efficiency'])->toBe(75.0)
            ->and((float) $data['rank_correlation'])->toBe(0.85)
            ->and($data['results'])->toHaveCount(1)
            ->and($data['results'][0]['rank'])->toBe(1)
            ->and($data['results'][0]['parameters']['fastPeriod'])->toBe(10);
    });

    it('JSON encodes float values correctly', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['fastPeriod' => 10];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['sharpe_ratio' => 2.0];
        $result1->oos_statistics = ['sharpe_ratio' => 1.5];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            walkForwardEfficiency: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
            classification: 'robust',
            interpretation: 'parameters generalize well to unseen data',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);

        expect($json)->toBeString()
            ->and(json_decode($json))->not->toBeNull();
    });
});
