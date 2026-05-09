<?php

namespace App\AlphaForge\Data\Service;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

readonly class DataAvailabilityService
{
    public function __construct(
        private string $marketDataPath,
        private BinaryStorageInterface $binaryStorage
    ) {}

    /**
     * Find all derived (non-OHLCV) data files that depend on a specific OHLCV source.
     *
     * @return array<int, array{filePath: string, type: string, dataType: int, brickSize: float}>
     */
    public function findDependencies(string $exchange, string $market, string $timeframe): array
    {
        $sanitizedSymbol = str_replace('/', '_', strtoupper($market));
        $directory = rtrim($this->marketDataPath, '/') . '/' . strtolower($exchange) . '/' . $sanitizedSymbol . '/' . $timeframe;

        if (! is_dir($directory)) {
            return [];
        }

        $dependencies = [];

        $files = File::files($directory);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'stchx') {
                continue;
            }

            $basename = $file->getBasename('.stchx');

            if ($basename === 'ohlcv') {
                continue;
            }

            try {
                $filePath = $file->getPathname();
                $header = $this->binaryStorage->readHeader($filePath);

                if ($header['numRecords'] === 0) {
                    continue;
                }

                $dependencies[] = [
                    'filePath' => $filePath,
                    'type' => $basename,
                    'dataType' => (int) $header['dataType'],
                    'brickSize' => (float) $header['brickSize'],
                ];
            } catch (\Throwable $e) {
                Log::error('Failed to read dependency file: {file}', [
                    'file' => $file->getPathname(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dependencies;
    }

    /**
     * Get a manifest of all available market data.
     *
     * @return array<int, array{symbol: string, exchange: string, timeframes: array<int, array{timeframe: string, type: string, dataType: int, brickSize: float, startDate: string, endDate: string, recordCount: int}>}>
     */
    public function getManifest(): array
    {
        if (! is_dir($this->marketDataPath)) {
            return [];
        }

        $manifest = [];

        $files = File::allFiles($this->marketDataPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'stchx') {
                continue;
            }

            try {
                // Path looks like: .../data/market/{exchange}/{symbol}/{timeframe}/{type}.stchx
                $relativePath = $file->getRelativePathname();
                $pathParts = explode('/', $relativePath);

                if (count($pathParts) < 4) {
                    continue;
                }

                $exchange = $pathParts[0];
                $symbol = str_replace('_', '/', $pathParts[1]);
                $timeframe = $pathParts[2]; // This is the actual timeframe (e.g., "1h", "4h")
                $type = $file->getBasename('.stchx'); // This is the data type (e.g., "ohlcv", "renko", "heikenashi")

                $filePath = $file->getPathname();
                $header = $this->binaryStorage->readHeader($filePath);

                if ($header['numRecords'] === 0) {
                    continue;
                }

                $firstRecord = $this->binaryStorage->readRecordByIndex($filePath, 0);
                $lastRecord = $this->binaryStorage->readRecordByIndex($filePath, $header['numRecords'] - 1);

                if ($firstRecord === null || $lastRecord === null) {
                    continue;
                }

                $timeframeData = [
                    'timeframe' => $timeframe,
                    'type' => $type,
                    'dataType' => (int) $header['dataType'],
                    'brickSize' => (float) $header['brickSize'],
                    'startDate' => gmdate('Y-m-d\TH:i:s\Z', (int) $firstRecord['timestamp']),
                    'endDate' => gmdate('Y-m-d\TH:i:s\Z', (int) $lastRecord['timestamp']),
                    'recordCount' => $header['numRecords'],
                ];

                $key = "{$exchange}:{$symbol}";

                if (! isset($manifest[$key])) {
                    $manifest[$key] = [
                        'symbol' => $symbol,
                        'exchange' => $exchange,
                        'timeframes' => [],
                    ];
                }

                $manifest[$key]['timeframes'][] = $timeframeData;
            } catch (\Throwable $e) {
                Log::error('Failed to process market data file: {file}', [
                    'file' => $file->getPathname(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values($manifest);
    }
}
