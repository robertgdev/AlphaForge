<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Safe\filemtime;
use function Safe\filesize;

class DataDeleteCommand extends Command
{
    use HasJsonOutput;
    use ParsesMarketDataArgs;

    protected $signature = 'alphaforge:data:delete
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {--force : Skip confirmation prompt}
        {--json : Output results as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Delete a market data file';

    public function handle(
        MarketDataFileService $fileService,
        FormattingService $formattingService
    ): int {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $force = $this->option('force');

        $filePath = $fileService->generateFilePath($exchange, $market, $timeframe);

        if (! file_exists($filePath)) {
            return $this->outputJsonError("No market data file found for {$exchange}/{$market}/{$timeframe}");
        }

        $fileSize = filesize($filePath);
        $fileSizeFormatted = $formattingService->formatFileSize($fileSize);
        $fileModified = date('Y-m-d H:i:s', filemtime($filePath));

        if (! $this->jsonEnabled()) {
            info('Market data file found:');
            $this->newLine();
            $this->displayMarketDataHeader($exchange, $market, $timeframe, [
                'File Path' => $filePath,
                'File Size' => $fileSizeFormatted,
                'Last Modified' => $fileModified,
            ]);
        }

        if (! $force) {
            if ($this->jsonEnabled()) {
                return $this->outputJsonError('Confirmation required. Use --force to delete without prompt.');
            }

            $confirmed = confirm(
                'Are you sure you want to delete this market data file?',
                false
            );

            if (! $confirmed) {
                warning('Delete operation cancelled.');

                $this->debugMemory();

                return self::SUCCESS;
            }
        }

        try {
            $result = $fileService->deleteFile($filePath);

            if (! $result['deleted']) {
                return $this->outputJsonError("Failed to delete file: {$filePath}");
            }

            if ($this->jsonEnabled()) {
                return $this->outputJson(true, [
                    'exchange' => $exchange,
                    'market' => $market,
                    'timeframe' => $timeframe,
                    'filePath' => $filePath,
                    'size' => $fileSize,
                    'deleted' => true,
                ]);
            }

            info('Market data file deleted successfully!');
            $this->components->twoColumnDetail('Deleted', $filePath);

            foreach ($result['removed_dirs'] as $removedDir) {
                $this->line("  Removed empty directory: {$removedDir}", 'comment');
            }

            $this->debugMemory();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->outputJsonError("Error deleting file: {$e->getMessage()}");
        }
    }
}
