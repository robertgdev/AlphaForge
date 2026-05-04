<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class HeikenAshiCommand extends Command
{
    use HasProgressBar;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alphaforge:heikenashi
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {--force : Force overwrite existing Heiken-Ashi file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to Heiken-Ashi candles';

    public function handle(HeikenAshiConverter $converter): int
    {
        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');
        $force = $this->option('force');

        try {
            // Get OHLC file info
            $ohlcvHeader = $converter->getOhlcvFileInfo($exchange, $market, $timeframe);
        } catch (StorageException $e) {
            error("OHLC file not found: {$e->getMessage()}");
            $this->components->twoColumnDetail(
                'Expected Path',
                "marketdata/{$exchange}/".str_replace('/', '_', $market)."/{$timeframe}/ohlcv.stchx"
            );

            return self::FAILURE;
        }

        // Check if Heiken-Ashi file already exists
        $heikenAshiExists = $converter->heikenAshiFileExists($exchange, $market, $timeframe);

        if ($heikenAshiExists && ! $force) {
            $heikenAshiPath = $converter->generateHeikenAshiFilePath($exchange, $market, $timeframe);
            warning('Heiken-Ashi file already exists for this configuration.');
            $this->components->twoColumnDetail('Existing File', $heikenAshiPath);

            $confirmed = confirm(
                'Do you want to overwrite the existing Heiken-Ashi file?',
                false
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        // Display conversion summary
        info('Starting Heiken-Ashi conversion...');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('OHLC Records', number_format($ohlcvHeader['numRecords']));
        $this->components->twoColumnDetail('Force Overwrite', $force ? 'Yes' : 'No');
        $this->newLine();

        try {
            $this->startProgressBar('Converting OHLC to Heiken-Ashi...');

            $filePath = $converter->convert(
                $exchange,
                $market,
                $timeframe,
                function (int $current, int $total) {
                    $this->updateProgress($current, $total);
                }
            );

            $this->finishProgressBar();

            // Read the generated file info
            $heikenAshiHeader = $converter->readHeikenAshiHeader($filePath);

            info('Heiken-Ashi conversion completed successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);
            $this->components->twoColumnDetail('Heiken-Ashi Candles Generated', number_format($heikenAshiHeader['numRecords']));

            return self::SUCCESS;
        } catch (StorageException $e) {
            $this->finishProgressBarOnError();
            error("Conversion failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();
            error("Unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
