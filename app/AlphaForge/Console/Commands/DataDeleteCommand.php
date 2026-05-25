<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Services\MarketDataFileService;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Safe\filemtime;
use function Safe\filesize;

class DataDeleteCommand extends Command
{
    protected $signature = 'alphaforge:data:delete
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {--force : Skip confirmation prompt}';

    protected $description = 'Delete a market data file';

    public function handle(
        MarketDataFileService $fileService,
        FormattingService $formattingService
    ): int {
        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');
        $force = $this->option('force');

        $filePath = $fileService->generateFilePath($exchange, $market, $timeframe);

        if (! file_exists($filePath)) {
            warning("No market data file found for {$exchange}/{$market}/{$timeframe}");
            $this->components->twoColumnDetail('Expected Path', $filePath);

            return self::FAILURE;
        }

        $fileSize = filesize($filePath);
        $fileSizeFormatted = $formattingService->formatFileSize($fileSize);
        $fileModified = date('Y-m-d H:i:s', filemtime($filePath));

        info('Market data file found:');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('File Path', $filePath);
        $this->components->twoColumnDetail('File Size', $fileSizeFormatted);
        $this->components->twoColumnDetail('Last Modified', $fileModified);
        $this->newLine();

        if (! $force) {
            $confirmed = confirm(
                'Are you sure you want to delete this market data file?',
                false
            );

            if (! $confirmed) {
                warning('Delete operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $result = $fileService->deleteFile($filePath);

            if (! $result['deleted']) {
                error("Failed to delete file: {$filePath}");

                return self::FAILURE;
            }

            info('Market data file deleted successfully!');
            $this->components->twoColumnDetail('Deleted', $filePath);

            foreach ($result['removed_dirs'] as $removedDir) {
                $this->line("  Removed empty directory: {$removedDir}", 'comment');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error("Error deleting file: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
