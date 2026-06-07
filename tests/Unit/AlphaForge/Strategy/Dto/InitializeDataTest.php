<?php

use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Strategy\Dto\InitializeData;

describe('InitializeData', function () {
    it('creates with required ohlcv parameter', function () {
        $ohlcv = Mockery::mock(OhlcvSeries::class);
        $dto = new InitializeData(ohlcv: $ohlcv);

        expect($dto->ohlcv)->toBe($ohlcv)
            ->and($dto->initialCapital)->toBe('10000')
            ->and($dto->multiTimeframe)->toBeNull();
    });

    it('accepts a custom initial capital', function () {
        $ohlcv = Mockery::mock(OhlcvSeries::class);
        $dto = new InitializeData(ohlcv: $ohlcv, initialCapital: '50000');

        expect($dto->initialCapital)->toBe('50000');
    });

    it('accepts multi-timeframe data', function () {
        $ohlcv = Mockery::mock(OhlcvSeries::class);
        $multiTf = Mockery::mock(MultiTimeframeOhlcvSeries::class);
        $dto = new InitializeData(ohlcv: $ohlcv, multiTimeframe: $multiTf);

        expect($dto->multiTimeframe)->toBe($multiTf);
    });

    it('multi-timeframe is null when omitted', function () {
        $ohlcv = Mockery::mock(OhlcvSeries::class);
        $dto = new InitializeData(ohlcv: $ohlcv, initialCapital: '2000');

        expect($dto->multiTimeframe)->toBeNull();
    });
});
