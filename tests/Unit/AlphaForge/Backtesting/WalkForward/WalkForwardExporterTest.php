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
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
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
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
            stabilityClassification: 'good',
            stabilityInterpretation: 'parameters generalize well to unseen data',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);

        $data = json_decode($json, true);
        expect($data)->not->toBeNull()
            ->and($data['stability_classification'])->toBe('good')
            ->and((float) $data['oos_is_ratio'])->toBe(75.0)
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
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
            stabilityClassification: 'good',
            stabilityInterpretation: 'parameters generalize well to unseen data',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);

        expect($json)->toBeString()
            ->and(json_decode($json))->not->toBeNull();
    });

    it('exports multiple rows in CSV', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['fastPeriod' => 10];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_return_percent' => 10.0, 'max_drawdown_percent' => 0.05, 'total_trades' => 20, 'win_rate' => 0.6];
        $result1->oos_statistics = ['total_return_percent' => 5.0, 'max_drawdown_percent' => 0.08, 'total_trades' => 15, 'win_rate' => 0.5];

        $result2 = new WalkForwardResult;
        $result2->rank = 2;
        $result2->parameters = ['fastPeriod' => 20];
        $result2->is_score = 1.5;
        $result2->oos_score = 1.0;
        $result2->score_degradation = 33.3;
        $result2->is_statistics = ['total_return_percent' => 8.0, 'max_drawdown_percent' => 0.03, 'total_trades' => 18, 'win_rate' => 0.55];
        $result2->oos_statistics = ['total_return_percent' => 4.0, 'max_drawdown_percent' => 0.06, 'total_trades' => 12, 'win_rate' => 0.45];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1, $result2],
            oosIsRatio: 71.4,
            robustCount: 2,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 29.15,
            medianDegradation: 29.15,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        $lines = explode("\n", $csv);
        expect($lines)->toHaveCount(3)
            ->and($lines[0])->toContain('rank')
            ->and($lines[1])->toContain('1')
            ->and($lines[2])->toContain('2');
    });

    it('escapes CSV values containing commas', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['label' => 'hello,world'];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_return_percent' => 10.0, 'max_drawdown_percent' => 0.05, 'total_trades' => 20, 'win_rate' => 0.6];
        $result1->oos_statistics = ['total_return_percent' => 5.0, 'max_drawdown_percent' => 0.08, 'total_trades' => 15, 'win_rate' => 0.5];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        expect($csv)->toContain('"hello,world"');
    });

    it('escapes CSV values containing double quotes', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['label' => 'say "hello"'];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_return_percent' => 10.0, 'max_drawdown_percent' => 0.05, 'total_trades' => 20, 'win_rate' => 0.6];
        $result1->oos_statistics = ['total_return_percent' => 5.0, 'max_drawdown_percent' => 0.08, 'total_trades' => 15, 'win_rate' => 0.5];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        expect($csv)->toContain('"say ""hello"""');
    });

    it('handles missing statistics keys in CSV as empty', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['fastPeriod' => 10];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_trades' => 20];
        $result1->oos_statistics = ['total_trades' => 15];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        expect($csv)->toBeString()
            ->and($csv)->toContain('rank')
            ->and($csv)->toContain('is_trades')
            ->and($csv)->toContain('oos_trades');
    });

    it('handles results with different parameter keys', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['fastPeriod' => 10, 'slowPeriod' => 50];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_return_percent' => 10.0, 'max_drawdown_percent' => 0.05, 'total_trades' => 20, 'win_rate' => 0.6];
        $result1->oos_statistics = ['total_return_percent' => 5.0, 'max_drawdown_percent' => 0.08, 'total_trades' => 15, 'win_rate' => 0.5];

        $result2 = new WalkForwardResult;
        $result2->rank = 2;
        $result2->parameters = ['fastPeriod' => 20, 'trailOffset' => 5];
        $result2->is_score = 1.5;
        $result2->oos_score = 1.0;
        $result2->score_degradation = 33.3;
        $result2->is_statistics = ['total_return_percent' => 8.0, 'max_drawdown_percent' => 0.03, 'total_trades' => 18, 'win_rate' => 0.55];
        $result2->oos_statistics = ['total_return_percent' => 4.0, 'max_drawdown_percent' => 0.06, 'total_trades' => 12, 'win_rate' => 0.45];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1, $result2],
            oosIsRatio: 71.4,
            robustCount: 2,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 29.15,
            medianDegradation: 29.15,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        expect($csv)->toContain('param_fastPeriod')
            ->and($csv)->toContain('param_slowPeriod')
            ->and($csv)->toContain('param_trailOffset');
    });

    it('includes all analysis metadata in JSON output', function () {
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
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
            oosIsRatioWarning: true,
            stabilityClassification: 'good',
            stabilityInterpretation: 'parameters generalize well to unseen data',
            economicPerformance: 'moderate',
            economicInterpretation: 'captures some market move',
            rankCorrelation: 0.85,
            rankStabilityLabel: 'stable',
            reliableCount: 1,
            reliableRatio: 1.0,
            minTrades: 10,
            suspiciousSharpe: true,
        );

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);
        $data = json_decode($json, true);

        expect($data)->toHaveKey('stability_classification')
            ->and($data)->toHaveKey('stability_interpretation')
            ->and($data)->toHaveKey('economic_performance')
            ->and($data)->toHaveKey('economic_interpretation')
            ->and($data)->toHaveKey('oos_is_ratio')
            ->and($data)->toHaveKey('oos_is_ratio_warning')
            ->and($data)->toHaveKey('robust_count')
            ->and($data)->toHaveKey('robust_ratio')
            ->and($data)->toHaveKey('beat_buy_hold_count')
            ->and($data)->toHaveKey('beat_buy_hold_ratio')
            ->and($data)->toHaveKey('return_gt_10_count')
            ->and($data)->toHaveKey('return_gt_10_ratio')
            ->and($data)->toHaveKey('sharpe_beat_benchmark_count')
            ->and($data)->toHaveKey('sharpe_beat_benchmark_ratio')
            ->and($data)->toHaveKey('median_is_score')
            ->and($data)->toHaveKey('median_oos_score')
            ->and($data)->toHaveKey('avg_degradation')
            ->and($data)->toHaveKey('median_degradation')
            ->and($data)->toHaveKey('rank_correlation')
            ->and($data)->toHaveKey('rank_stability_label')
            ->and($data)->toHaveKey('reliable_count')
            ->and($data)->toHaveKey('reliable_ratio')
            ->and($data)->toHaveKey('min_trades')
            ->and($data)->toHaveKey('suspicious_sharpe')
            ->and($data)->toHaveKey('time_in_market')
            ->and($data)->toHaveKey('exposure_adjusted_target')
            ->and($data)->toHaveKey('capture_ratio')
            ->and($data['reliable_count'])->toBe(1)
            ->and((float) $data['reliable_ratio'])->toBe(1.0)
            ->and($data['min_trades'])->toBe(10)
            ->and($data['suspicious_sharpe'])->toBeTrue()
            ->and($data['oos_is_ratio_warning'])->toBeTrue();
    });

    it('exports empty JSON results array when no results', function () {
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

        $exporter = new WalkForwardExporter;
        $json = $exporter->toJson($analysis);
        $data = json_decode($json, true);

        expect($data['results'])->toBe([]);
    });

    it('CSV parameter columns are sorted alphabetically', function () {
        $result1 = new WalkForwardResult;
        $result1->rank = 1;
        $result1->parameters = ['zebra' => 1, 'alpha' => 2, 'middle' => 3];
        $result1->is_score = 2.0;
        $result1->oos_score = 1.5;
        $result1->score_degradation = 25.0;
        $result1->is_statistics = ['total_return_percent' => 10.0, 'max_drawdown_percent' => 0.05, 'total_trades' => 20, 'win_rate' => 0.6];
        $result1->oos_statistics = ['total_return_percent' => 5.0, 'max_drawdown_percent' => 0.08, 'total_trades' => 15, 'win_rate' => 0.5];

        $wfRun = Mockery::mock(WalkForwardRun::class);
        $analysis = new WalkForwardAnalysis(
            walkForwardRun: $wfRun,
            results: [$result1],
            oosIsRatio: 75.0,
            robustCount: 1,
            robustRatio: 1.0,
            beatBuyHoldCount: 0,
            beatBuyHoldRatio: 0.0,
            returnGt10Count: 0,
            returnGt10Ratio: 0.0,
            sharpeBeatBenchmarkCount: 0,
            sharpeBeatBenchmarkRatio: 0.0,
            medianIsScore: 0.0,
            medianOosScore: 0.0,
            avgDegradation: 25.0,
            medianDegradation: 25.0,
            bestOosRank: 1,
            bestOosResult: $result1,
        );

        $exporter = new WalkForwardExporter;
        $csv = $exporter->toCsv($analysis);

        $headerLine = explode("\n", $csv)[0];
        $alphaPos = strpos($headerLine, 'param_alpha');
        $middlePos = strpos($headerLine, 'param_middle');
        $zebraPos = strpos($headerLine, 'param_zebra');

        expect($alphaPos)->toBeLessThan($middlePos)
            ->and($middlePos)->toBeLessThan($zebraPos);
    });
});
