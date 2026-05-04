<?php

namespace App\AlphaForge\Data\Service;

use App\AlphaForge\Services\MarketDataFileService;
use RuntimeException;

use function Safe\filesize;

class DataRepairService
{
    private const HEADER_LENGTH = 64;

    private const RECORD_LENGTH = 48;

    public function __construct(
        private BinaryStorageInterface $binaryStorage,
        private MarketDataFileService $fileService
    ) {}

    /**
     * Check and optionally repair a single data file.
     *
     * @return array{status: 'ok'|'corrupted'|'fixed'|'error'|'skipped', header_count: int, actual_count: int, message: string}
     */
    public function checkAndRepairFile(string $exchange, string $symbol, string $timeframe, bool $dryRun): array
    {
        $filePath = $this->fileService->generateFilePath($exchange, $symbol, $timeframe);

        if (! file_exists($filePath)) {
            return [
                'status' => 'skipped',
                'header_count' => 0,
                'actual_count' => 0,
                'message' => "File not found: {$filePath}",
            ];
        }

        $fileSize = filesize($filePath);

        if ($fileSize < self::HEADER_LENGTH) {
            return [
                'status' => 'error',
                'header_count' => 0,
                'actual_count' => 0,
                'message' => "File too small to be valid: {$filePath}",
            ];
        }

        $actualRecordCount = (int) (($fileSize - self::HEADER_LENGTH) / self::RECORD_LENGTH);

        try {
            $header = $this->binaryStorage->readHeader($filePath);
            /** @var int $headerRecordCount */
            $headerRecordCount = $header['numRecords'] ?? 0;
        } catch (RuntimeException $e) {
            return [
                'status' => 'error',
                'header_count' => 0,
                'actual_count' => $actualRecordCount,
                'message' => "Could not read header from {$filePath}: {$e->getMessage()}",
            ];
        }

        if ($headerRecordCount === $actualRecordCount) {
            return [
                'status' => 'ok',
                'header_count' => $headerRecordCount,
                'actual_count' => $actualRecordCount,
                'message' => "{$exchange}/{$symbol}/{$timeframe}: OK ({$headerRecordCount} records)",
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'corrupted',
                'header_count' => $headerRecordCount,
                'actual_count' => $actualRecordCount,
                'message' => "{$exchange}/{$symbol}/{$timeframe}: CORRUPTED (header: {$headerRecordCount}, actual: {$actualRecordCount})",
            ];
        }

        try {
            $this->binaryStorage->updateRecordCount($filePath, $actualRecordCount);

            return [
                'status' => 'fixed',
                'header_count' => $headerRecordCount,
                'actual_count' => $actualRecordCount,
                'message' => "{$exchange}/{$symbol}/{$timeframe}: FIXED (header: {$headerRecordCount} -> {$actualRecordCount})",
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'header_count' => $headerRecordCount,
                'actual_count' => $actualRecordCount,
                'message' => "Failed to repair {$exchange}/{$symbol}/{$timeframe}: {$e->getMessage()}",
            ];
        }
    }
}
