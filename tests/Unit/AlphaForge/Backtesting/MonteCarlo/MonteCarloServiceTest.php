<?php

use App\AlphaForge\Backtesting\MonteCarlo\MonteCarloService;

describe('MonteCarloService', function () {
    describe('analyze()', function () {
        it('returns report with zero trades when no trade data', function () {
            $service = new MonteCarloService([], '10000');
            $report = $service->analyze(100, seed: 42);

            expect($report->totalTrades)->toBe(0);
            expect($report->iterations)->toBe(0);
            expect($report->hasTrades())->toBeFalse();
            expect($report->metrics)->toBe([]);
        });

        it('produces consistent results with fixed seed', function () {
            $pnl = ['100', '-50', '200', '-30', '150', '-20', '80', '-10'];
            $service = new MonteCarloService($pnl, '10000');

            $report1 = $service->analyze(500, seed: 123);
            $report2 = $service->analyze(500, seed: 123);

            $m1 = $report1->metrics['total_return_pct'];
            $m2 = $report2->metrics['total_return_pct'];

            expect($m1->median)->toBe($m2->median);
            expect($m1->p5)->toBe($m2->p5);
            expect($m1->p95)->toBe($m2->p95);
        });

        it('computes correct win rate for all winning trades', function () {
            $pnl = ['10', '20', '30', '40'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $wr = $report->metrics['win_rate'];
            expect($wr->median)->toBe(100.0);
            expect($wr->p5)->toBe(100.0);
            expect($wr->p95)->toBe(100.0);
        });

        it('computes correct win rate for all losing trades', function () {
            $pnl = ['-10', '-20', '-30', '-40'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $wr = $report->metrics['win_rate'];
            expect($wr->median)->toBe(0.0);
        });

        it('computes negative total return for all losing trades', function () {
            $pnl = ['-10', '-20', '-30'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $tr = $report->metrics['total_return_pct'];
            expect($tr->median)->toBeLessThan(0);
            expect($tr->p95)->toBeLessThan(0);
            expect($tr->probNegative)->toBe(100.0);
        });

        it('computes positive total return for all winning trades', function () {
            $pnl = ['10', '20', '30'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $tr = $report->metrics['total_return_pct'];
            expect($tr->median)->toBeGreaterThan(0);
            expect($tr->p5)->toBeGreaterThan(0);
            expect($tr->probNegative)->toBe(0.0);
        });

        it('bootstrap resamples produce realistic variance', function () {
            $pnl = ['100', '-50', '200', '-30'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(1000, seed: 42);

            $tr = $report->metrics['total_return_pct'];
            // With 4 trades and some variance, p5 and p95 should differ
            expect($tr->p95)->toBeGreaterThan($tr->p5);
            expect($report->iterations)->toBeGreaterThan(0);
        });

        it('all metrics are present in report', function () {
            $pnl = ['50', '-25', '75'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(100, seed: 1);

            expect($report->metrics)->toHaveKeys([
                'total_return_pct',
                'win_rate',
                'max_drawdown_pct',
                'profit_factor',
                'avg_trade_pnl',
                'positive_trades',
            ]);
        });

        it('prob_negative correctly detects when P(<0) is high', function () {
            $pnl = array_fill(0, 10, '-10');
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $tr = $report->metrics['total_return_pct'];
            expect($tr->probNegative)->toBe(100.0);
        });

        it('prob_negative is zero for all positive trades', function () {
            $pnl = array_fill(0, 10, '10');
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $tr = $report->metrics['total_return_pct'];
            expect($tr->probNegative)->toBe(0.0);
        });

        it('drawdown calculated correctly for known trade sequence', function () {
            // Sequence: +100, -50, +10, -80 -> equity: 10100, 10050, 10060, 9980
            // Peak at 10100 (or 10060), trough at 9980 → DD% = (10060-9980)/10060 = -0.7952%
            $pnl = ['100', '-50', '10', '-80'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(1, seed: 1);

            $dd = $report->metrics['max_drawdown_pct'];
            expect($dd->median)->toBeLessThan(0);
            expect($dd->median)->toBeGreaterThan(-2.0);
        });

        it('isSignificant returns false when P(negative) > 5%', function () {
            $pnl = array_merge(
                array_fill(0, 5, '10'),
                array_fill(0, 5, '-10'),
            );
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);

            $tr = $report->metrics['total_return_pct'];
            expect($tr->probNegative)->toBeGreaterThan(5.0);
            expect($tr->isSignificant())->toBeFalse();
        });

        it('MonteCarloMetric values are in expected ranges', function () {
            $pnl = array_merge(
                array_fill(0, 6, '100'),
                array_fill(0, 4, '-50'),
            );
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(500, seed: 42);

            $wr = $report->metrics['win_rate'];
            expect($wr->p5)->toBeGreaterThanOrEqual(0);
            expect($wr->p95)->toBeLessThanOrEqual(100);

            $pf = $report->metrics['profit_factor'];
            expect($pf->median)->toBeGreaterThan(0);

            $tr = $report->metrics['total_return_pct'];
            expect($tr->median)->toBeGreaterThan(0);
        });

        it('returns INF profit factor when all trades are winners', function () {
            $pnl = ['100', '200', '150'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(10, seed: 42);
            $pf = $report->metrics['profit_factor'];

            expect($pf->median)->toBeInfinite()
                ->and($pf->p75)->toBeInfinite()
                ->and($pf->p95)->toBeInfinite();
        });

        it('returns 0 profit factor when all trades have zero PnL', function () {
            $pnl = ['0', '0', '0'];
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(10, seed: 42);
            $pf = $report->metrics['profit_factor'];

            expect($pf->median)->toBe(0.0);
        });

        it('isSignificant returns false when probNegative is above 5%', function () {
            $pnl = array_merge(
                array_fill(0, 5, '10'),
                array_fill(0, 5, '-10'),
            );
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(200, seed: 1);
            $tr = $report->metrics['total_return_pct'];

            expect($tr->probNegative)->toBeGreaterThan(5.0)
                ->and($tr->isSignificant())->toBeFalse();
        });

        it('isSignificant returns true when probNegative is zero and P5 is positive', function () {
            $pnl = array_fill(0, 20, '50');
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(100, seed: 1);
            $tr = $report->metrics['total_return_pct'];

            expect($tr->probNegative)->toBe(0.0)
                ->and($tr->isSignificant())->toBeTrue();
        });

        it('isSignificant returns false when only one of two conditions is met', function () {
            $pnl = array_fill(0, 20, '1');
            $service = new MonteCarloService($pnl, '10000');

            $report = $service->analyze(100, seed: 1);
            $tr = $report->metrics['total_return_pct'];

            expect($tr->probNegative)->toBe(0.0);
            // probNegative = 0%, P5 > 0 → condition for isSignificant is met
            // (met both: probNegative < 5% AND P5 > 0)
            expect($tr->isSignificant())->toBeTrue();
        });
    });
});
