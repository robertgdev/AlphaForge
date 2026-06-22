<?php

namespace App\AlphaForge\Services;

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\Dto\SignalEvaluationResult;

class TradeSignalEvaluator
{
    public function __construct(
        private readonly MarketDataFileService $fileService,
        private readonly BinaryStorageInterface $binaryStorage,
    ) {}

    public function evaluate(TradeSignal $signal): SignalEvaluationResult
    {
        $filePath = $this->fileService->generateFilePath(
            $signal->exchange,
            $signal->symbol,
            $signal->timeframe,
            'ohlcv'
        );

        if (! file_exists($filePath)) {
            return new SignalEvaluationResult(
                status: 'open',
                errorMessage: "OHLCV data file not found: {$filePath}"
            );
        }

        try {
            $candles = \iterator_to_array(
                $this->binaryStorage->readRecordsByTimestampRange(
                    $filePath,
                    $signal->entry_timestamp,
                    PHP_INT_MAX
                )
            );
        } catch (StorageException) {
            return new SignalEvaluationResult(
                status: 'open',
                errorMessage: "Failed to read OHLCV data from: {$filePath}"
            );
        }

        if (empty($candles)) {
            return new SignalEvaluationResult(
                status: 'open',
                errorMessage: 'No OHLCV candles available after entry timestamp.'
            );
        }

        $waterMark = (float) $signal->trailing_stop_high_water_mark;

        foreach ($candles as $candle) {
            $open = (float) $candle['open'];
            $high = (float) $candle['high'];
            $low = (float) $candle['low'];
            $close = (float) $candle['close'];

            $isLong = $signal->direction === 'LONG';

            if ($signal->trailing_stop_enabled && $signal->trailing_stop_percent !== null) {
                $distance = $close * ((float) $signal->trailing_stop_percent / 100);

                if ($isLong) {
                    if ($waterMark === 0.0) {
                        $waterMark = (float) $signal->entry_price;
                    }
                    if ($high > $waterMark) {
                        $waterMark = $high;
                    }
                    $trailPrice = $waterMark - $distance;
                } else {
                    if ($waterMark === 0.0) {
                        $waterMark = (float) $signal->entry_price;
                    }
                    if ($low < $waterMark) {
                        $waterMark = $low;
                    }
                    $trailPrice = $waterMark + $distance;
                }
            }

            $stopLoss = (float) $signal->stop_loss;
            $takeProfit = (float) $signal->take_profit;

            if ($isLong) {
                if ($low <= $stopLoss) {
                    return $this->buildResult($signal, $stopLoss, $candle['timestamp'], 'stop_loss');
                }

                if (isset($trailPrice) && $low <= $trailPrice) {
                    return $this->buildResult($signal, $trailPrice, $candle['timestamp'], 'trailing_stop');
                }

                if ($high >= $takeProfit) {
                    return $this->buildResult($signal, $takeProfit, $candle['timestamp'], 'take_profit');
                }
            } else {
                if ($high >= $stopLoss) {
                    return $this->buildResult($signal, $stopLoss, $candle['timestamp'], 'stop_loss');
                }

                if (isset($trailPrice) && $high >= $trailPrice) {
                    return $this->buildResult($signal, $trailPrice, $candle['timestamp'], 'trailing_stop');
                }

                if ($low <= $takeProfit) {
                    return $this->buildResult($signal, $takeProfit, $candle['timestamp'], 'take_profit');
                }
            }
        }

        if ($signal->trailing_stop_enabled && $waterMark > 0) {
            $signal->updateWaterMark($waterMark);
        } else {
            $signal->touchEvaluation();
        }

        return new SignalEvaluationResult(status: 'open');
    }

    private function buildResult(TradeSignal $signal, float $exitPrice, int $exitTimestamp, string $exitReason): SignalEvaluationResult
    {
        $entryPrice = (float) $signal->entry_price;

        if ($signal->direction === 'LONG') {
            $pnlAbs = $exitPrice - $entryPrice;
        } else {
            $pnlAbs = $entryPrice - $exitPrice;
        }

        $pnlPct = ($pnlAbs / $entryPrice) * 100;
        $status = $pnlPct > 0 ? 'winner' : 'loser';

        return new SignalEvaluationResult(
            status: $status,
            exitPrice: $exitPrice,
            exitTimestamp: $exitTimestamp,
            exitReason: $exitReason,
            profitLossPct: $pnlPct,
            profitLossAbs: $pnlAbs,
        );
    }
}
