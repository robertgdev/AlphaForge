<?php

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\Dto\SignalEvaluationResult;
use App\AlphaForge\Services\MarketDataFileService;
use App\AlphaForge\Services\TradeSignalEvaluator;

function makeSignal(array $overrides = []): TradeSignal
{
    $signal = new TradeSignal(array_merge([
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'direction' => 'LONG',
        'entry_price' => '50000',
        'stop_loss' => '49000',
        'take_profit' => '52000',
        'trailing_stop_enabled' => false,
        'trailing_stop_percent' => null,
        'trailing_stop_high_water_mark' => '0',
        'entry_timestamp' => 1700000000,
        'timeframe' => '1h',
        'status' => 'open',
    ], $overrides));

    $signal->id = '019a0000-0000-7000-8000-000000000001';

    return $signal;
}

function tempFilePath(): string
{
    return sys_get_temp_dir().'/ts_eval_test_'.uniqid().'.stchx';
}

$tempFiles = [];

function registerTempFile(string $path): void
{
    global $tempFiles;
    $tempFiles[] = $path;
}

function cleanupTempFiles(): void
{
    global $tempFiles;
    foreach ($tempFiles as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
    $tempFiles = [];
}

function makeTempFile(string $path): void
{
    touch($path);
    registerTempFile($path);
}

describe('TradeSignalEvaluator', function () {
    beforeEach(function () {
        $this->fileService = Mockery::mock(MarketDataFileService::class);
        $this->binaryStorage = Mockery::mock(BinaryStorageInterface::class);
        $this->evaluator = new TradeSignalEvaluator($this->fileService, $this->binaryStorage);
    });

    afterEach(function () {
        Mockery::close();
        cleanupTempFiles();
    });

    describe('evaluate()', function () {
        it('returns open when OHLCV file does not exist', function () {
            $signal = makeSignal();

            $this->fileService->shouldReceive('generateFilePath')
                ->with('binance', 'BTCUSDT', '1h', 'ohlcv')
                ->andReturn('/nonexistent/path_'.uniqid().'.stchx');

            $result = $this->evaluator->evaluate($signal);

            expect($result)->toBeInstanceOf(SignalEvaluationResult::class);
            expect($result->status)->toBe('open');
            expect($result->errorMessage)->toContain('not found');
        });

        it('returns open when no candles exist after entry timestamp', function () {
            $signal = makeSignal();
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')
                ->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result)->toBeInstanceOf(SignalEvaluationResult::class);
            expect($result->status)->toBe('open');
            expect($result->errorMessage)->toContain('No OHLCV candles');
        });

        it('returns open when StorageException is thrown', function () {
            $signal = makeSignal();
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')
                ->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andThrow(new StorageException('Test error'));

            $result = $this->evaluator->evaluate($signal);

            expect($result)->toBeInstanceOf(SignalEvaluationResult::class);
            expect($result->status)->toBe('open');
            expect($result->errorMessage)->toContain('Failed to read OHLCV');
        });

        it('detects stop loss hit for LONG', function () {
            $signal = makeSignal([
                'stop_loss' => '49000',
                'entry_price' => '50000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '50100', 'low' => '48800', 'close' => '49000'];

                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('loser');
            expect($result->exitReason)->toBe('stop_loss');
            expect($result->exitPrice)->toBe(49000.0);
            expect($result->profitLossPct)->toBe(-2.0);
        });

        it('detects stop loss hit for SHORT', function () {
            $signal = makeSignal([
                'direction' => 'SHORT',
                'stop_loss' => '51000',
                'entry_price' => '50000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '51200', 'low' => '49800', 'close' => '51000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('loser');
            expect($result->exitReason)->toBe('stop_loss');
            expect($result->exitPrice)->toBe(51000.0);
            expect($result->profitLossPct)->toBe(-2.0);
        });

        it('detects take profit hit for LONG', function () {
            $signal = makeSignal([
                'take_profit' => '52000',
                'entry_price' => '50000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '52100', 'low' => '49900', 'close' => '52000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('winner');
            expect($result->exitReason)->toBe('take_profit');
            expect($result->exitPrice)->toBe(52000.0);
            expect($result->profitLossPct)->toBe(4.0);
        });

        it('detects take profit hit for SHORT', function () {
            $signal = makeSignal([
                'direction' => 'SHORT',
                'take_profit' => '48000',
                'stop_loss' => '999999',
                'entry_price' => '50000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '50100', 'low' => '47900', 'close' => '48000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('winner');
            expect($result->exitReason)->toBe('take_profit');
            expect($result->exitPrice)->toBe(48000.0);
            expect($result->profitLossPct)->toBe(4.0);
        });

        it('detects trailing stop hit for LONG', function () {
            $signal = makeSignal([
                'trailing_stop_enabled' => true,
                'trailing_stop_percent' => '5',
                'entry_price' => '50000',
                'take_profit' => '999999',
                'stop_loss' => '1',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '51000', 'low' => '49000', 'close' => '50500'];
                    yield ['timestamp' => 1700000200, 'open' => '50500', 'high' => '50600', 'low' => '47000', 'close' => '48000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('loser');
            expect($result->exitReason)->toBe('trailing_stop');
            expect($result->exitPrice)->toBe(48600.0);
        });

        it('detects trailing stop hit for SHORT', function () {
            $signal = makeSignal([
                'direction' => 'SHORT',
                'trailing_stop_enabled' => true,
                'trailing_stop_percent' => '5',
                'entry_price' => '50000',
                'take_profit' => '1',
                'stop_loss' => '999999',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '50100', 'low' => '49000', 'close' => '49500'];
                    yield ['timestamp' => 1700000200, 'open' => '49500', 'high' => '51000', 'low' => '48000', 'close' => '48200'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('loser');
            expect($result->exitReason)->toBe('trailing_stop');
        });

        it('returns open when no exit condition is hit', function () {
            $signal = makeSignal([
                'stop_loss' => '49000',
                'take_profit' => '52000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '50500', 'low' => '49500', 'close' => '50300'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('open');
            expect($result->exitReason)->toBeNull();
        });

        it('preserves trailing stop water mark across evaluations', function () {
            $signal = makeSignal([
                'trailing_stop_enabled' => true,
                'trailing_stop_percent' => '5',
                'trailing_stop_high_water_mark' => '51000',
                'entry_price' => '50000',
                'take_profit' => '999999',
                'stop_loss' => '1',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50500', 'high' => '51200', 'low' => '47500', 'close' => '48000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('loser');
            expect($result->exitReason)->toBe('trailing_stop');
            expect($result->exitPrice)->toBe(48800.0);
        });

        it('checks stop loss before trailing stop before take profit', function () {
            $signal = makeSignal([
                'trailing_stop_enabled' => true,
                'trailing_stop_percent' => '5',
                'stop_loss' => '49000',
                'take_profit' => '52000',
                'entry_price' => '50000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '53000', 'low' => '48000', 'close' => '50000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->exitReason)->toBe('stop_loss');
        });

        it('correctly computes PnL for breakeven exit', function () {
            $signal = makeSignal([
                'take_profit' => '50000',
                'entry_price' => '50000',
            ]);
            $path = tempFilePath();
            makeTempFile($path);

            $this->fileService->shouldReceive('generateFilePath')->andReturn($path);
            $this->binaryStorage->shouldReceive('readRecordsByTimestampRange')
                ->with($path, $signal->entry_timestamp, PHP_INT_MAX)
                ->andReturn((function () {
                    yield ['timestamp' => 1700000100, 'open' => '50000', 'high' => '50100', 'low' => '49900', 'close' => '50000'];
                    yield from [];
                })());

            $result = $this->evaluator->evaluate($signal);

            expect($result->status)->toBe('loser');
            expect($result->profitLossPct)->toBe(0.0);
            expect($result->profitLossAbs)->toBe(0.0);
        });
    });
});
