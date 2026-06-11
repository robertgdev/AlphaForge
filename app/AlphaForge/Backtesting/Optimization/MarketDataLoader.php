<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataPathBuilder;
use Carbon\Carbon;
use RuntimeException;

class MarketDataLoader
{
    public function __construct(
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly MarketDataPathBuilder $pathBuilder,
    ) {}

    /**
     * @param  array<string>  $symbols
     */
    public function load(
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?TimeframeEnum $executionTimeframe = null,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): MarketDataSnapshot {
        $signalData = [];

        foreach ($symbols as $symbol) {
            $filePath = $this->getMarketDataPath($symbol, $timeframe, $exchange, $dataType, $brickSize, $atrPeriod);
            $rawData = $this->readRawOhlcvData($filePath);

            if ($startDate || $endDate) {
                $rawData = $this->filterRawDataByDateRange($rawData, $startDate, $endDate);
            }

            $signalData[$symbol] = [
                'data' => $rawData,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ];
        }

        $executionData = null;

        if ($executionTimeframe !== null) {
            $executionData = [];

            foreach ($symbols as $symbol) {
                $filePath = $this->getMarketDataPath($symbol, $executionTimeframe, $exchange, 'ohlcv');

                if (! file_exists($filePath)) {
                    throw new RuntimeException(
                        "Execution timeframe data ({$executionTimeframe->value}) not found for {$symbol} on {$exchange}. "
                        .'Download the data first or remove the execution_timeframe setting.'
                    );
                }

                $rawData = $this->readRawOhlcvData($filePath);

                if ($startDate || $endDate) {
                    $rawData = $this->filterRawDataByDateRange($rawData, $startDate, $endDate);
                }

                $executionData[$symbol] = [
                    'data' => $rawData,
                    'symbol' => $symbol,
                    'timeframe' => $executionTimeframe,
                ];
            }

            $this->validateTimeAlignment($signalData, $executionData);
        }

        return new MarketDataSnapshot($signalData, $executionData);
    }

    /**
     * @return array{timestamp: array, open: array, high: array, low: array, close: array, volume: array}
     */
    private function readRawOhlcvData(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Market data file not found: {$filePath}. Download the data first using alphaforge:data:import.");
        }

        $records = iterator_to_array($this->binaryStorage->readRecordsSequentially($filePath));

        if (count($records) === 0) {
            throw new RuntimeException("Market data file is empty: {$filePath}. Download the data again.");
        }

        $timestamps = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];

        foreach ($records as $record) {
            $timestamps[] = $record['timestamp'];
            $opens[] = $record['open'];
            $highs[] = $record['high'];
            $lows[] = $record['low'];
            $closes[] = $record['close'];
            $volumes[] = $record['volume'];
        }

        return [
            'timestamp' => $timestamps,
            'open' => $opens,
            'high' => $highs,
            'low' => $lows,
            'close' => $closes,
            'volume' => $volumes,
        ];
    }

    /**
     * @param  array  $rawData  Raw OHLCV data arrays
     * @return array Filtered raw data
     */
    private function filterRawDataByDateRange(array $rawData, ?Carbon $startDate, ?Carbon $endDate): array
    {
        $timestamps = $rawData['timestamp'];
        $count = count($timestamps);
        $startIndex = 0;
        $endIndex = $count - 1;

        if ($startDate) {
            for ($i = 0; $i < $count; $i++) {
                if ($timestamps[$i] >= $startDate->timestamp) {
                    $startIndex = $i;
                    break;
                }
            }
        }

        if ($endDate) {
            for ($i = $count - 1; $i >= 0; $i--) {
                if ($timestamps[$i] <= $endDate->timestamp) {
                    $endIndex = $i;
                    break;
                }
            }
        }

        $length = $endIndex - $startIndex + 1;

        return [
            'timestamp' => array_slice($rawData['timestamp'], $startIndex, $length),
            'open' => array_slice($rawData['open'], $startIndex, $length),
            'high' => array_slice($rawData['high'], $startIndex, $length),
            'low' => array_slice($rawData['low'], $startIndex, $length),
            'close' => array_slice($rawData['close'], $startIndex, $length),
            'volume' => array_slice($rawData['volume'], $startIndex, $length),
        ];
    }

    /**
     * @param  array<string, array>  $signalData
     * @param  array<string, array>  $executionData
     */
    private function validateTimeAlignment(array $signalData, array $executionData): void
    {
        foreach ($signalData as $symbol => $signalEntry) {
            $execEntry = $executionData[$symbol] ?? null;
            if ($execEntry === null) {
                throw new RuntimeException("Execution data missing for symbol: {$symbol}");
            }

            $signalTimestamps = $signalEntry['data']['timestamp'];
            $execTimestamps = $execEntry['data']['timestamp'];

            if (count($signalTimestamps) === 0 || count($execTimestamps) === 0) {
                throw new RuntimeException(
                    "No data available for time alignment validation on {$symbol}."
                );
            }

            $signalStart = $signalTimestamps[0];
            $signalEnd = $signalTimestamps[count($signalTimestamps) - 1];
            $execStart = $execTimestamps[0];
            $execEnd = $execTimestamps[count($execTimestamps) - 1];

            if ($execStart > $signalStart || $execEnd < $signalEnd) {
                throw new RuntimeException(
                    "Execution timeframe data for {$symbol} does not cover the full signal timeframe date range. "
                    ."Signal: {$signalStart}-{$signalEnd}, Execution: {$execStart}-{$execEnd}. "
                    .'Download execution data that covers the full period.'
                );
            }
        }
    }

    private function getMarketDataPath(
        string $symbol,
        TimeframeEnum $timeframe,
        string $exchange,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): string {
        return $this->pathBuilder->build($exchange, $symbol, $timeframe, $dataType, $brickSize, $atrPeriod);
    }
}
