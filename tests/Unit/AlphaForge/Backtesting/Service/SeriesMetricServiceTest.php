<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Backtesting\Service\SeriesMetricService;
use App\AlphaForge\Common\Model\Series;

describe('SeriesMetricService', function () {
    beforeEach(function () {
        $this->service = new SeriesMetricService;
        $this->cursor = new BacktestCursor;
    });

    describe('empty series', function () {
        it('returns empty metrics for empty series', function () {
            $series = new Series([], $this->cursor);

            $result = $this->service->calculate($series);

            expect($result['count'])->toBe(0)
                ->and($result['min'])->toBe('0')
                ->and($result['max'])->toBe('0')
                ->and($result['mean'])->toBe('0')
                ->and($result['median'])->toBe('0')
                ->and($result['std_dev'])->toBe('0')
                ->and($result['variance'])->toBe('0')
                ->and($result['sum'])->toBe('0')
                ->and($result['range'])->toBe('0')
                ->and($result['skewness'])->toBe('0')
                ->and($result['kurtosis'])->toBe('0');
        });
    });

    describe('basic metrics', function () {
        it('calculates count', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect($result['count'])->toBe(5);
        });

        it('calculates min', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['min'], '10', 8))->toBe(0);
        });

        it('calculates max', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['max'], '50', 8))->toBe(0);
        });

        it('calculates mean', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['mean'], '30', 8))->toBe(0);
        });

        it('calculates sum', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['sum'], '150', 8))->toBe(0);
        });

        it('calculates range', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['range'], '40', 8))->toBe(0);
        });
    });

    describe('median', function () {
        it('calculates median for odd count', function () {
            $series = new Series(['10', '20', '30', '40', '50'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['median'], '30', 8))->toBe(0);
        });

        it('calculates median for even count', function () {
            $series = new Series(['10', '20', '30', '40'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['median'], '25', 8))->toBe(0);
        });
    });

    describe('variance and std_dev', function () {
        it('calculates population variance', function () {
            $series = new Series(['2', '4', '4', '4', '5', '5', '7', '9'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['variance'], '4', 0))->toBe(0);
        });

        it('calculates standard deviation', function () {
            $series = new Series(['2', '4', '4', '4', '5', '5', '7', '9'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['std_dev'], '2', 0))->toBe(0);
        });

        it('returns zero std dev for constant series', function () {
            $series = new Series(['5', '5', '5', '5'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['std_dev'], '0', 8))->toBe(0)
                ->and(bccomp($result['variance'], '0', 8))->toBe(0);
        });
    });

    describe('quartiles', function () {
        it('calculates Q1, Q2, Q3', function () {
            $series = new Series(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['quartiles']['q1'], '3', 0))->toBe(0)
                ->and(bccomp($result['quartiles']['q2'], '5', 0))->toBe(0)
                ->and(bccomp($result['quartiles']['q3'], '7', 0))->toBe(0);
        });

        it('returns quartiles structure', function () {
            $series = new Series(['10', '20', '30'], $this->cursor);

            $result = $this->service->calculate($series);

            expect($result['quartiles'])->toHaveKeys(['q1', 'q2', 'q3']);
        });
    });

    describe('skewness and kurtosis', function () {
        it('returns zero skewness for less than 3 values', function () {
            $series = new Series(['10', '20'], $this->cursor);

            $result = $this->service->calculate($series);

            expect($result['skewness'])->toBe('0');
        });

        it('returns zero kurtosis for less than 4 values', function () {
            $series = new Series(['10', '20', '30'], $this->cursor);

            $result = $this->service->calculate($series);

            expect($result['kurtosis'])->toBe('0');
        });

        it('calculates skewness for symmetric distribution near zero', function () {
            $series = new Series(['1', '2', '3', '4', '5'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(bccomp($result['skewness'], '0', 2))->toBe(0);
        });

        it('calculates kurtosis for a dataset', function () {
            $series = new Series(['1', '2', '3', '4', '5', '6', '7', '8'], $this->cursor);

            $result = $this->service->calculate($series);

            expect(is_numeric($result['kurtosis']))->toBeTrue();
        });
    });

    describe('financial values', function () {
        it('handles decimal string values', function () {
            $series = new Series(
                ['50000.50', '51000.75', '52000.25', '53000.00', '54000.10'],
                $this->cursor
            );

            $result = $this->service->calculate($series);

            expect(bccomp($result['min'], '50000.50', 2))->toBe(0)
                ->and(bccomp($result['max'], '54000.10', 2))->toBe(0)
                ->and($result['count'])->toBe(5);
        });
    });
});
