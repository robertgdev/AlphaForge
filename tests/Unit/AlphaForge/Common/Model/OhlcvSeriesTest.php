<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;

describe('OhlcvSeries', function () {
    beforeEach(function () {
        $this->cursor = new BacktestCursor;

        $this->marketData = [
            'timestamp' => [1700000000, 1700003600, 1700007200],
            'open' => ['100.00', '105.00', '103.00'],
            'high' => ['110.00', '108.00', '107.00'],
            'low' => ['95.00', '102.00', '100.00'],
            'close' => ['105.00', '103.00', '106.00'],
            'volume' => ['1000.00', '1500.00', '1200.00'],
        ];

        $this->ohlcv = new OhlcvSeries($this->marketData, $this->cursor);
    });

    it('returns correct series for each OHLCV field', function () {
        expect($this->ohlcv->getOpens()->toArray())->toBe($this->marketData['open'])
            ->and($this->ohlcv->getHighs()->toArray())->toBe($this->marketData['high'])
            ->and($this->ohlcv->getLows()->toArray())->toBe($this->marketData['low'])
            ->and($this->ohlcv->getCloses()->toArray())->toBe($this->marketData['close'])
            ->and($this->ohlcv->getVolumes()->toArray())->toBe($this->marketData['volume']);
    });

    it('returns timestamps via both getTimestamp and getTimestamps', function () {
        expect($this->ohlcv->getTimestamp()->toArray())->toBe($this->marketData['timestamp'])
            ->and($this->ohlcv->getTimestamps()->toArray())->toBe($this->marketData['timestamp']);
    });

    it('alias getters return same data as full getters', function () {
        expect($this->ohlcv->getOpen()->toArray())->toBe($this->ohlcv->getOpens()->toArray())
            ->and($this->ohlcv->getHigh()->toArray())->toBe($this->ohlcv->getHighs()->toArray())
            ->and($this->ohlcv->getLow()->toArray())->toBe($this->ohlcv->getLows()->toArray())
            ->and($this->ohlcv->getClose()->toArray())->toBe($this->ohlcv->getCloses()->toArray())
            ->and($this->ohlcv->getVolume()->toArray())->toBe($this->ohlcv->getVolumes()->toArray());
    });

    it('calculates HLC3 correctly', function () {
        $hlc3 = $this->ohlcv->getHlc3();
        $values = $hlc3->toArray();

        $expected0 = bcdiv(bcadd('110.00', bcadd('95.00', '105.00')), '3', 0);
        expect(bccomp($values[0], $expected0, 1))->toBe(0);
    });

    it('handles empty market data', function () {
        $emptyData = [
            'timestamp' => [], 'open' => [], 'high' => [],
            'low' => [], 'close' => [], 'volume' => [],
        ];

        $ohlcv = new OhlcvSeries($emptyData, $this->cursor);

        expect($ohlcv->getOpens()->count())->toBe(0)
            ->and($ohlcv->getHlc3()->count())->toBe(0);
    });

    it('can slice data', function () {
        $sliced = $this->ohlcv->slice(1, 2);

        expect($sliced->getOpens()->toArray())->toBe(['105.00', '103.00'])
            ->and($sliced->getCloses()->toArray())->toBe(['103.00', '106.00'])
            ->and($sliced->getTimestamps()->toArray())->toBe([1700003600, 1700007200]);
    });

    it('slice preserves cursor reference', function () {
        $this->cursor->currentIndex = 1;

        $sliced = $this->ohlcv->slice(0, 2);

        expect($sliced->getOpens()->count())->toBe(2);
    });

    it('returns null symbol and timeframe when not provided', function () {
        expect($this->ohlcv->getSymbol())->toBeNull()
            ->and($this->ohlcv->getTimeframe())->toBeNull();
    });

    it('returns symbol and timeframe when provided in constructor', function () {
        $ohlcv = new OhlcvSeries($this->marketData, $this->cursor, 'BTC/USDT', TimeframeEnum::H1);

        expect($ohlcv->getSymbol())->toBe('BTC/USDT')
            ->and($ohlcv->getTimeframe())->toBe(TimeframeEnum::H1);
    });

    it('slice does not preserve symbol and timeframe', function () {
        $ohlcv = new OhlcvSeries($this->marketData, $this->cursor, 'ETH/USDT', TimeframeEnum::H4);
        $sliced = $ohlcv->slice(0, 2);

        expect($sliced->getSymbol())->toBeNull()
            ->and($sliced->getTimeframe())->toBeNull();
    });
});
