<?php

use App\AlphaForge\Backtesting\Dto\DataTypeConfig;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Services\AggregateDataService;
use App\AlphaForge\Services\DataAutoGenerator;
use App\AlphaForge\Services\MarketDataFileService;

describe('DataAutoGenerator', function () {
    beforeEach(function () {
        $this->renkoConverter = Mockery::mock(RenkoConverter::class);
        $this->atrRenkoConverter = Mockery::mock(AtrRenkoConverter::class);
        $this->heikenAshiConverter = Mockery::mock(HeikenAshiConverter::class);
        $this->aggregateDataService = Mockery::mock(AggregateDataService::class);
        $this->fileService = Mockery::mock(MarketDataFileService::class);

        $this->generator = new DataAutoGenerator(
            $this->renkoConverter,
            $this->atrRenkoConverter,
            $this->heikenAshiConverter,
            $this->aggregateDataService,
            $this->fileService,
        );

        $this->ohlcvPath = sys_get_temp_dir() . '/alphaforge_test_ohlcv_' . uniqid() . '.stchx';
        touch($this->ohlcvPath);

        $ohlcvPath = $this->ohlcvPath;
        $this->fileService->shouldReceive('generateFilePath')
            ->andReturnUsing(function ($exchange, $symbol, $tf, $type) use ($ohlcvPath) {
                if ($type === 'ohlcv') {
                    return $ohlcvPath;
                }
                return sys_get_temp_dir() . '/alphaforge_test_derived_' . uniqid() . '.stchx';
            });
    });

    afterEach(function () {
        if (file_exists($this->ohlcvPath)) { unlink($this->ohlcvPath); }
        $files = glob(sys_get_temp_dir() . '/alphaforge_test_*');
        if ($files) {
            foreach ($files as $f) { if (file_exists($f)) { unlink($f); } }
        }
    });

    it('returns empty arrays for ohlcv data-type', function () {
        $config = DataTypeConfig::fromOptions('ohlcv', null, null);
        $result = $this->generator->autoGenerate($config, 'binance', 'BTC/USDT', '1h');

        expect($result['generated'])->toHaveCount(0);
        expect($result['errors'])->toHaveCount(0);
    });

    it('triggers Heiken-Ashi conversion when file missing', function () {
        $expectedPath = '/tmp/gen_ha_' . uniqid() . '.stchx';

        $this->heikenAshiConverter->shouldReceive('generateHeikenAshiFilePath')
            ->with('binance', 'BTC/USDT', '1h')
            ->andReturn($expectedPath);
        $this->heikenAshiConverter->shouldReceive('convert')
            ->with('binance', 'BTC/USDT', '1h')
            ->andReturn($expectedPath);

        $config = DataTypeConfig::fromOptions('heikenashi', null, null);
        $result = $this->generator->autoGenerate($config, 'binance', 'BTC/USDT', '1h');

        expect($result['generated'])->toContain($expectedPath);
        expect($result['errors'])->toHaveCount(0);
    });

    it('triggers Renko conversion when file missing', function () {
        $expectedPath = '/tmp/gen_renko_' . uniqid() . '.stchx';

        $this->renkoConverter->shouldReceive('generateRenkoFilePath')
            ->with('binance', 'BTC/USDT', '1h', 10.0)
            ->andReturn($expectedPath);
        $this->renkoConverter->shouldReceive('convert')
            ->with('binance', 'BTC/USDT', '1h', 10.0)
            ->andReturn($expectedPath);

        $config = DataTypeConfig::fromOptions('renko', '10', null);
        $result = $this->generator->autoGenerate($config, 'binance', 'BTC/USDT', '1h');

        expect($result['generated'])->toContain($expectedPath);
        expect($result['errors'])->toHaveCount(0);
    });

    it('triggers ATR-Renko conversion when file missing', function () {
        $expectedPath = '/tmp/gen_atr_' . uniqid() . '.stchx';

        $this->atrRenkoConverter->shouldReceive('generateAtrRenkoFilePath')
            ->with('binance', 'BTC/USDT', '1h', 14)
            ->andReturn($expectedPath);
        $this->atrRenkoConverter->shouldReceive('convert')
            ->with('binance', 'BTC/USDT', '1h', 14)
            ->andReturn($expectedPath);

        $config = DataTypeConfig::fromOptions('atr_renko', null, '14');
        $result = $this->generator->autoGenerate($config, 'binance', 'BTC/USDT', '1h');

        expect($result['generated'])->toContain($expectedPath);
        expect($result['errors'])->toHaveCount(0);
    });

    it('skips generation when derived file already exists', function () {
        $existingPath = sys_get_temp_dir() . '/alphaforge_test_ha_exist_' . uniqid() . '.stchx';
        touch($existingPath);

        $this->heikenAshiConverter->shouldReceive('generateHeikenAshiFilePath')
            ->with('binance', 'BTC/USDT', '1h')
            ->andReturn($existingPath);

        $config = DataTypeConfig::fromOptions('heikenashi', null, null);
        $result = $this->generator->autoGenerate($config, 'binance', 'BTC/USDT', '1h');

        expect($result['generated'])->toContain($existingPath);
        expect($result['errors'])->toHaveCount(0);
    });

    it('invokes output callback with progress messages', function () {
        $messages = [];
        $expectedPath = '/tmp/gen_cb_' . uniqid() . '.stchx';

        $this->heikenAshiConverter->shouldReceive('generateHeikenAshiFilePath')->andReturn($expectedPath);
        $this->heikenAshiConverter->shouldReceive('convert')->andReturn($expectedPath);

        $config = DataTypeConfig::fromOptions('heikenashi', null, null);

        $this->generator->autoGenerate($config, 'binance', 'BTC/USDT', '1h',
            output: function (string $msg) use (&$messages) { $messages[] = $msg; }
        );

        expect($messages)->toHaveCount(2);
    });
});
