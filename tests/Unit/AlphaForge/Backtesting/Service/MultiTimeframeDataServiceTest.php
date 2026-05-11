<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Backtesting\Service\MultiTimeframeDataService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;

describe('MultiTimeframeDataService', function () {
    beforeEach(function () {
        $this->service = new MultiTimeframeDataService;
        $this->cursor = new BacktestCursor;
    });

    describe('resample', function () {
        it('resamples 1h to 4h data correctly', function () {
            $marketData = [
                'timestamp' => [
                    1700006400, 1700010000, 1700013600, 1700017200,
                    1700020800, 1700024400, 1700028000, 1700031600,
                ],
                'open' => ['100', '105', '103', '108', '110', '107', '112', '109'],
                'high' => ['110', '108', '107', '115', '120', '112', '118', '114'],
                'low' => ['95',  '102', '100', '106', '108', '104', '110', '106'],
                'close' => ['105', '103', '108', '110', '107', '112', '109', '111'],
                'volume' => ['1000', '1500', '1200', '2000', '1800', '1600', '1400', '1700'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H1);
            $result = $this->service->resample($source, TimeframeEnum::H4);

            expect($result)->toBeInstanceOf(OhlcvSeries::class)
                ->and($result->getTimeframe())->toBe(TimeframeEnum::H4)
                ->and($result->getSymbol())->toBe('BTC/USDT')
                ->and($result->getTimestamps()->count())->toBe(2);
        });

        it('uses first open and last close in each period', function () {
            $marketData = [
                'timestamp' => [1700006400, 1700010000, 1700013600, 1700017200],
                'open' => ['100', '105', '103', '108'],
                'high' => ['110', '108', '107', '115'],
                'low' => ['95',  '102', '100', '106'],
                'close' => ['105', '103', '108', '110'],
                'volume' => ['1000', '1500', '1200', '2000'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H1);
            $result = $this->service->resample($source, TimeframeEnum::H4);

            $opens = $result->getOpens()->getVector();
            $closes = $result->getCloses()->getVector();

            expect($opens->get(0))->toBe('100')
                ->and($closes->get(0))->toBe('110');
        });

        it('computes highest high and lowest low per period', function () {
            $marketData = [
                'timestamp' => [1700006400, 1700010000, 1700013600, 1700017200],
                'open' => ['100', '105', '103', '108'],
                'high' => ['110', '108', '107', '115'],
                'low' => ['95',  '102', '100', '106'],
                'close' => ['105', '103', '108', '110'],
                'volume' => ['1000', '1500', '1200', '2000'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H1);
            $result = $this->service->resample($source, TimeframeEnum::H4);

            $highs = $result->getHighs()->getVector();
            $lows = $result->getLows()->getVector();

            expect($highs->get(0))->toBe('115')
                ->and($lows->get(0))->toBe('95');
        });

        it('sums volumes per period', function () {
            $marketData = [
                'timestamp' => [1700006400, 1700010000, 1700013600, 1700017200],
                'open' => ['100', '105', '103', '108'],
                'high' => ['110', '108', '107', '115'],
                'low' => ['95',  '102', '100', '106'],
                'close' => ['105', '103', '108', '110'],
                'volume' => ['1000', '1500', '1200', '2000'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H1);
            $result = $this->service->resample($source, TimeframeEnum::H4);

            $volumes = $result->getVolumes()->getVector();

            expect($volumes->get(0))->toBe(5700);
        });

        it('throws when target timeframe is lower than source', function () {
            $marketData = [
                'timestamp' => [1700006400],
                'open' => ['100'],
                'high' => ['110'],
                'low' => ['95'],
                'close' => ['105'],
                'volume' => ['1000'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H4);

            expect(fn () => $this->service->resample($source, TimeframeEnum::H1))
                ->toThrow(InvalidArgumentException::class);
        });

        it('throws when source timeframe is not set', function () {
            $marketData = [
                'timestamp' => [1700006400],
                'open' => ['100'],
                'high' => ['110'],
                'low' => ['95'],
                'close' => ['105'],
                'volume' => ['1000'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', null);

            expect(fn () => $this->service->resample($source, TimeframeEnum::H4))
                ->toThrow(TypeError::class);
        });

        it('resamples 1m to 1h data correctly', function () {
            $records = [];
            $baseTs = 1700006400;
            for ($i = 0; $i < 60; $i++) {
                $records['timestamp'][] = $baseTs + $i * 60;
                $records['open'][] = (string) (100 + $i);
                $records['high'][] = (string) (110 + $i);
                $records['low'][] = (string) (90 + $i);
                $records['close'][] = (string) (105 + $i);
                $records['volume'][] = '100';
            }

            $source = new OhlcvSeries($records, $this->cursor, 'BTC/USDT', TimeframeEnum::M1);
            $result = $this->service->resample($source, TimeframeEnum::H1);

            expect($result->getTimestamps()->count())->toBe(1)
                ->and($result->getOpens()->getVector()->get(0))->toBe('100')
                ->and($result->getCloses()->getVector()->get(0))->toBe('164');
        });
    });

    describe('aggregate', function () {
        it('returns base series plus resampled series', function () {
            $marketData = [
                'timestamp' => [1700006400, 1700010000, 1700013600, 1700017200],
                'open' => ['100', '105', '103', '108'],
                'high' => ['110', '108', '107', '115'],
                'low' => ['95',  '102', '100', '106'],
                'close' => ['105', '103', '108', '110'],
                'volume' => ['1000', '1500', '1200', '2000'],
            ];

            $source = new OhlcvSeries($marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H1);
            $result = $this->service->aggregate($source, [TimeframeEnum::H4]);

            expect($result)->toHaveCount(2)
                ->and($result[TimeframeEnum::H1->value])->not->toBeNull()
                ->and($result[TimeframeEnum::H4->value])->not->toBeNull();
        });
    });
});
