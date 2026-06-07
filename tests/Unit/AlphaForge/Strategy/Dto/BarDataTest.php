<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Order\Model\PortfolioManager;
use App\AlphaForge\Strategy\Dto\BarData;

describe('BarData', function () {
    it('creates with all required fields', function () {
        $cursor = new BacktestCursor;
        $ohlcv = Mockery::mock(OhlcvSeries::class);
        $portfolio = new PortfolioManager('10000');

        $dto = new BarData(
            cursor: $cursor,
            ohlcv: $ohlcv,
            portfolio: $portfolio,
            symbol: 'BTC/USDT',
        );

        expect($dto->cursor)->toBe($cursor)
            ->and($dto->ohlcv)->toBe($ohlcv)
            ->and($dto->portfolio)->toBe($portfolio)
            ->and($dto->symbol)->toBe('BTC/USDT')
            ->and($dto->multiTimeframe)->toBeNull();
    });

    it('defaults multiTimeframe to null', function () {
        $dto = new BarData(
            cursor: new BacktestCursor,
            ohlcv: Mockery::mock(OhlcvSeries::class),
            portfolio: new PortfolioManager('10000'),
            symbol: 'ETH/USDT',
        );

        expect($dto->multiTimeframe)->toBeNull();
    });

    it('accepts optional multiTimeframe', function () {
        $multiTf = Mockery::mock(MultiTimeframeOhlcvSeries::class);
        $dto = new BarData(
            cursor: new BacktestCursor,
            ohlcv: Mockery::mock(OhlcvSeries::class),
            portfolio: new PortfolioManager('10000'),
            symbol: 'ETH/USDT',
            multiTimeframe: $multiTf,
        );

        expect($dto->multiTimeframe)->toBe($multiTf);
    });
});
