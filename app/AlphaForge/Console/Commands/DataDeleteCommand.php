<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Services\MarketDataFileService;
use App\AlphaForge\Console\Commands\Concerns\DebugMemory;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Safe\filemtime;
use function Safe\filesize;

class DataDeleteCommand extends Command
{
    use ParsesMarketDataArgs;
    use DebugMemory;

    protected $signature = 'alphaforge:data:delete
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {--force : Skip confirmation prompt}
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
            warning("No market data file found for {$exchange}/{$market}/{$timeframe}");
            $this->components->twoColumnDetail('Expected Path', $filePath);

            $this->debugMemory();
            return self::FAILURE;
        }

        $fileSize = filesize($filePath);
        $fileSizeFormatted = $formattingService->formatFileSize($fileSize);
        $fileModified = date('Y-m-d H:i:s', filemtime($filePath));

        info('Market data file found:');
        $this->newLine();
        $this->displayMarketDataHeader($exchange, $market, $timeframe, [
            'File Path' => $filePath,
            'File Size' => $fileSizeFormatted,
            'Last Modified' => $fileModified,
        ]);

        if (! $force) {
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
                error("Failed to delete file: {$filePath}");

                $this->debugMemory();
                return self::FAILURE;
            }

            info('Market data file deleted successfully!');
            $this->components->twoColumnDetail('Deleted', $filePath);

            foreach ($result['removed_dirs'] as $removedDir) {
                $this->line("  Removed empty directory: {$removedDir}", 'comment');
            }

            $this->debugMemory();
            return self::SUCCESS;
        } catch (\Throwable $e) {
            error("Error deleting file: {$e->getMessage()}");

            $this->debugMemory();
            return self::FAILURE;
        }
    }
}
