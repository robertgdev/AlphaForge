<?php

namespace App\AlphaForge\Services;

use BaconQrCode\Exception\InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Safe\rmdir;
use function Safe\scandir;
use function Safe\unlink;

readonly class MarketDataFileService
{
    public function __construct(
        private string $marketDataPath
    ) {}

    /**
     * Generate the file path for storing market data.
     *
     * @param  string  $exchangeId  The exchange identifier (e.g., 'binance', 'kraken')
     * @param  string  $symbol  The trading pair symbol (e.g., 'BTC/USDT')
     * @param  string  $timeframe  The timeframe (e.g., '1m', '1h', '1d')
     * @return string The full path to the market data file
     */
    public function generateFilePath(string $exchangeId, string $symbol, string $timeframe, string $type = 'ohlcv'): string
    {
        $exchangeId = Str::trim($exchangeId);
        $symbol = Str::trim($symbol);
        $sanitizedSymbol = Str::of($symbol)->replace('/', '_')->trim();
        $timeframe = Str::trim($timeframe);
        $type = Str::trim($type);

        throw_if(! $exchangeId, new InvalidArgumentException("Invalid exchange [$exchangeId] received"));
        throw_if(! $sanitizedSymbol, new InvalidArgumentException("Invalid symbol [$sanitizedSymbol] received"));
        throw_if(! $timeframe, new InvalidArgumentException("Invalid timeframe [$timeframe] received"));
        throw_if(! $type, new InvalidArgumentException("Invalid type [$type] received"));

        return sprintf(
            '%s/%s/%s/%s/%s.stchx',
            rtrim($this->marketDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            $timeframe,
            $type
        );
    }

    /**
     * Delete a market data file and clean up empty parent directories.
     *
     * @return array{deleted: bool, path: string, removed_dirs: list<string>}
     */
    public function deleteFile(string $filePath): array
    {
        $removedDirs = [];

        if (! file_exists($filePath)) {
            return ['deleted' => false, 'path' => $filePath, 'removed_dirs' => $removedDirs];
        }

        try {
            unlink($filePath);
        } catch (\Throwable) {
            return ['deleted' => false, 'path' => $filePath, 'removed_dirs' => $removedDirs];
        }

        $dir = dirname($filePath);

        if (is_dir($dir) && $this->isDirEmpty($dir)) {
            rmdir($dir);
            $removedDirs[] = $dir;

            $parentDir = dirname($dir);
            if (is_dir($parentDir) && $this->isDirEmpty($parentDir)) {
                rmdir($parentDir);
                $removedDirs[] = $parentDir;
            }
        }

        return ['deleted' => true, 'path' => $filePath, 'removed_dirs' => $removedDirs];
    }

    /**
     * Clean up a temporary file.
     *
     * @param  string  $filePath  The path to the file to clean up
     * @param  string  $reason  Optional reason for cleanup (for logging purposes)
     */
    public function cleanupFile(string $filePath, string $reason = ''): void
    {
        if (file_exists($filePath)) {
            try {
                unlink($filePath);
            } catch (\Throwable) {
                Log::warning('Could not clean up temporary file: {file}', ['file' => $filePath]);
            }
        }
    }

    private function isDirEmpty(string $dir): bool
    {
        $files = scandir($dir);

        return count($files) === 2;
    }
}
