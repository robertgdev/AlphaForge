<?php

use App\AlphaForge\Data\Exception\DataFileNotFoundException;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataInspectionService;

describe('DataInspectionService', function () {
    beforeEach(function () {
        $this->storage = Mockery::mock(BinaryStorageInterface::class);
        $this->baseDataPath = sys_get_temp_dir().'/alphaforge_data';
        $this->service = new DataInspectionService($this->storage, $this->baseDataPath);
    });

    describe('inspect', function () {
        it('throws DataFileNotFoundException when file does not exist', function () {
            expect(fn () => $this->service->inspect('binance', 'BTC/USDT', '1h'))
                ->toThrow(DataFileNotFoundException::class);
        });

        it('returns inspection result with header', function () {
            $filePath = $this->baseDataPath.'/binance/BTC_USDT/1h/ohlcv.stchx';
            $dir = dirname($filePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($filePath, str_repeat('x', 64));

            $header = [
                'magic' => 'STCHXBF1',
                'version' => 2,
                'headerLength' => 64,
                'recordLength' => 48,
                'tsFormat' => 1,
                'dataType' => 1,
                'numRecords' => 0,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1h',
                'brickSize' => 0.0,
            ];

            $this->storage->shouldReceive('readHeader')
                ->once()
                ->andReturn($header);

            $result = $this->service->inspect('binance', 'BTC/USDT', '1h');

            expect($result)->toHaveKey('filePath')
                ->and($result)->toHaveKey('fileSize')
                ->and($result)->toHaveKey('header')
                ->and($result)->toHaveKey('sample')
                ->and($result)->toHaveKey('validation')
                ->and($result['header']['symbol'])->toBe('BTC/USDT');

            unlink($filePath);
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir(dirname($dir, 2));
        });

        it('includes head and tail samples when records exist', function () {
            $filePath = $this->baseDataPath.'/binance/BTC_USDT/1h/ohlcv.stchx';
            $dir = dirname($filePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($filePath, str_repeat('x', 64));

            $header = [
                'magic' => 'STCHXBF1',
                'version' => 2,
                'headerLength' => 64,
                'recordLength' => 48,
                'tsFormat' => 1,
                'dataType' => 1,
                'numRecords' => 3,
                'symbol' => 'BTC/USDT',
                'timeframe' => '1h',
                'brickSize' => 0.0,
            ];

            $this->storage->shouldReceive('readHeader')->andReturn($header);
            $this->storage->shouldReceive('readRecordByIndex')->andReturn(
                ['timestamp' => 1000, 'open' => 50000.0, 'high' => 50100.0, 'low' => 49900.0, 'close' => 50050.0, 'volume' => 100.0],
                ['timestamp' => 4600, 'open' => 50100.0, 'high' => 50200.0, 'low' => 50000.0, 'close' => 50150.0, 'volume' => 200.0],
                ['timestamp' => 8200, 'open' => 50200.0, 'high' => 50300.0, 'low' => 50100.0, 'close' => 50250.0, 'volume' => 300.0],
            );

            $generator = (function () { yield from []; })();
            $this->storage->shouldReceive('readRecordsSequentially')->andReturn($generator);

            $result = $this->service->inspect('binance', 'BTC/USDT', '1h');

            expect($result['sample']['head'])->toHaveCount(3);

            unlink($filePath);
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir(dirname($dir, 2));
        });
    });
});
