<?php

namespace App\AlphaForge\Services;

use App\AlphaForge\Backtesting\Dto\DataTypeConfig;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Conversion\RenkoConverter;

class DataAutoGenerator
{
    public function __construct(
        private RenkoConverter $renkoConverter,
        private AtrRenkoConverter $atrRenkoConverter,
        private HeikenAshiConverter $heikenAshiConverter,
        private AggregateDataService $aggregateDataService,
        private MarketDataFileService $fileService,
    ) {}

    /**
     * @param  array<int, string>  $additionalTimeframes
     * @param  (callable(string): void)|null  $output
     * @return array{generated: list<string>, errors: list<string>}
     */
    public function autoGenerate(
        DataTypeConfig $dataTypeConfig,
        string $exchange,
        string $symbol,
        string $primaryTimeframe,
        ?string $executionTimeframe = null,
        array $additionalTimeframes = [],
        ?callable $output = null,
    ): array {
        $generated = [];
        $errors = [];
        $info = function (string $msg) use ($output): void {
            if ($output !== null) {
                $output($msg);
            }
        };

        $primaryPath = $this->fileService->generateFilePath($exchange, $symbol, $primaryTimeframe, 'ohlcv');
        if (! file_exists($primaryPath)) {
            $r = $this->generateTimeframeData($exchange, $symbol, $primaryTimeframe, $info);
            if ($r !== null) {
                $generated[] = $r;
            } else {
                $errors[] = "No source data to derive OHLCV for primary timeframe '{$primaryTimeframe}'.";
            }
        }

        switch ($dataTypeConfig->dataType) {
            case 'heikenashi':
                $generated[] = $this->ensureHeikenAshi($exchange, $symbol, $primaryTimeframe, $info);
                break;
            case 'renko':
                $generated[] = $this->ensureRenko($exchange, $symbol, $primaryTimeframe, $dataTypeConfig->brickSize, $info);
                break;
            case 'atr_renko':
                $generated[] = $this->ensureAtrRenko($exchange, $symbol, $primaryTimeframe, $dataTypeConfig->atrPeriod, $info);
                break;
        }

        if ($executionTimeframe !== null && $executionTimeframe !== $primaryTimeframe) {
            $execPath = $this->fileService->generateFilePath($exchange, $symbol, $executionTimeframe, 'ohlcv');
            if (! file_exists($execPath)) {
                $r = $this->generateTimeframeData($exchange, $symbol, $executionTimeframe, $info);
                if ($r !== null) {
                    $generated[] = $r;
                } else {
                    $errors[] = "No source data to derive execution timeframe '{$executionTimeframe}'.";
                }
            }
        }

        foreach ($additionalTimeframes as $tf) {
            $tfPath = $this->fileService->generateFilePath($exchange, $symbol, $tf, 'ohlcv');
            if (! file_exists($tfPath)) {
                $r = $this->generateTimeframeData($exchange, $symbol, $tf, $info);
                if ($r !== null) {
                    $generated[] = $r;
                } else {
                    $errors[] = "No source data to derive additional timeframe '{$tf}'.";
                }
            }
        }

        return ['generated' => $generated, 'errors' => $errors];
    }

    private function ensureHeikenAshi(string $exchange, string $symbol, string $timeframe, callable $info): string
    {
        $path = $this->heikenAshiConverter->generateHeikenAshiFilePath($exchange, $symbol, $timeframe);
        if (file_exists($path)) {
            return $path;
        }
        $info("Auto-generating Heiken-Ashi for {$symbol} / {$timeframe}...");
        $path = $this->heikenAshiConverter->convert($exchange, $symbol, $timeframe);
        $info("  ✓ Heiken-Ashi generated: {$path}");

        return $path;
    }

    private function ensureRenko(string $exchange, string $symbol, string $timeframe, float $brickSize, callable $info): string
    {
        $path = $this->renkoConverter->generateRenkoFilePath($exchange, $symbol, $timeframe, $brickSize);
        if (file_exists($path)) {
            return $path;
        }
        $info("Auto-generating Renko for {$symbol} / {$timeframe} (brick={$brickSize})...");
        $path = $this->renkoConverter->convert($exchange, $symbol, $timeframe, $brickSize);
        $info("  ✓ Renko generated: {$path}");

        return $path;
    }

    private function ensureAtrRenko(string $exchange, string $symbol, string $timeframe, int $atrPeriod, callable $info): string
    {
        $path = $this->atrRenkoConverter->generateAtrRenkoFilePath($exchange, $symbol, $timeframe, $atrPeriod);
        if (file_exists($path)) {
            return $path;
        }
        $info("Auto-generating ATR-Renko for {$symbol} / {$timeframe} (ATR={$atrPeriod})...");
        $path = $this->atrRenkoConverter->convert($exchange, $symbol, $timeframe, $atrPeriod);
        $info("  ✓ ATR-Renko generated: {$path}");

        return $path;
    }

    private function generateTimeframeData(string $exchange, string $symbol, string $targetValue, callable $info): ?string
    {
        $targetTf = TimeframeEnum::tryFrom($targetValue);
        if ($targetTf === null) {
            return null;
        }

        $targetSec = $targetTf->toSeconds();
        $candidates = TimeframeEnum::cases();
        usort($candidates, fn (TimeframeEnum $a, TimeframeEnum $b) => $b->toSeconds() <=> $a->toSeconds());

        foreach ($candidates as $c) {
            $cSec = $c->toSeconds();
            if ($cSec >= $targetSec || $targetSec % $cSec !== 0) {
                continue;
            }

            $srcPath = $this->fileService->generateFilePath($exchange, $symbol, $c->value, 'ohlcv');
            if (! file_exists($srcPath)) {
                continue;
            }

            $targetPath = $this->fileService->generateFilePath($exchange, $symbol, $targetValue, 'ohlcv');
            $info("Auto-generating {$targetValue} from {$c->value} for {$symbol}...");

            try {
                $this->aggregateDataService->aggregateData($srcPath, $targetPath, $symbol, $c, $targetTf);
                $info("  ✓ {$targetValue} data generated: {$targetPath}");

                return $targetPath;
            } catch (\Throwable $e) {
                $info("  ⚠ Aggregation failed: {$e->getMessage()}");

                return null;
            }
        }

        return null;
    }
}
