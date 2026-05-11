<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use Ds\Map;

describe('MultiTimeframeOhlcvSeries', function () {
    beforeEach(function () {
        $this->cursor = new BacktestCursor;

        $this->primaryData = [
            'timestamp' => [1700000000, 1700003600, 1700007200],
            'open' => ['100.00', '105.00', '103.00'],
            'high' => ['110.00', '108.00', '107.00'],
            'low' => ['95.00', '102.00', '100.00'],
            'close' => ['105.00', '103.00', '106.00'],
            'volume' => ['1000.00', '1500.00', '1200.00'],
        ];

        $this->secondaryData = [
            'timestamp' => [1700000000, 1700007200],
            'open' => ['100.00', '103.00'],
            'high' => ['110.00', '107.00'],
            'low' => ['95.00', '100.00'],
            'close' => ['103.00', '106.00'],
            'volume' => ['2500.00', '1200.00'],
        ];

        $secondaryMap = new Map;
        $secondaryMap->put('4h', new OhlcvSeries($this->secondaryData, new BacktestCursor));

        $this->multi = new MultiTimeframeOhlcvSeries($this->primaryData, $secondaryMap, $this->cursor);
    });

    it('returns primary ohlcv series', function () {
        $primary = $this->multi->getPrimary();

        expect($primary)->toBeInstanceOf(OhlcvSeries::class)
            ->and($primary->getCloses()->toArray())->toBe($this->primaryData['close']);
    });

    it('returns secondary ohlcv series by timeframe key', function () {
        $secondary = $this->multi->getSecondary('4h');

        expect($secondary)->toBeInstanceOf(OhlcvSeries::class)
            ->and($secondary->getCloses()->toArray())->toBe($this->secondaryData['close']);
    });

    it('returns null for unknown timeframe', function () {
        expect($this->multi->getSecondary('1d'))->toBeNull();
    });

    it('returns all timeframe keys', function () {
        expect($this->multi->getAllTimeframes())->toBe(['4h']);
    });

    it('delegates open/high/low/close/volume/timestamp to primary', function () {
        expect($this->multi->getOpen()->toArray())->toBe($this->primaryData['open'])
            ->and($this->multi->getHigh()->toArray())->toBe($this->primaryData['high'])
            ->and($this->multi->getLow()->toArray())->toBe($this->primaryData['low'])
            ->and($this->multi->getClose()->toArray())->toBe($this->primaryData['close'])
            ->and($this->multi->getVolume()->toArray())->toBe($this->primaryData['volume'])
            ->and($this->multi->getTimestamp()->toArray())->toBe($this->primaryData['timestamp']);
    });

    it('delegates hlc3 to primary', function () {
        expect($this->multi->getHlc3()->count())->toBe(3);
    });

    it('provides magic property access for primary data', function () {
        expect($this->multi->open->toArray())->toBe($this->primaryData['open'])
            ->and($this->multi->high->toArray())->toBe($this->primaryData['high'])
            ->and($this->multi->low->toArray())->toBe($this->primaryData['low'])
            ->and($this->multi->close->toArray())->toBe($this->primaryData['close'])
            ->and($this->multi->volume->toArray())->toBe($this->primaryData['volume'])
            ->and($this->multi->timestamp->toArray())->toBe($this->primaryData['timestamp'])
            ->and($this->multi->hlc3->count())->toBe(3);
    });

    it('throws for invalid magic property', function () {
        $this->multi->invalidProperty;
    })->throws(InvalidArgumentException::class);

    it('handles array data in secondary (converts to OhlcvSeries)', function () {
        $secondaryMap = new Map;
        $secondaryMap->put('4h', $this->secondaryData);

        $multi = new MultiTimeframeOhlcvSeries($this->primaryData, $secondaryMap, $this->cursor);

        $secondary = $multi->getSecondary('4h');

        expect($secondary)->toBeInstanceOf(OhlcvSeries::class);
    });
});
