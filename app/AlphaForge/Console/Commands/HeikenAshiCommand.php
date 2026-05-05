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
        {--force : Force overwrite existing Heiken-Ashi file}
        {--update : Incrementally update the Heiken-Ashi file by appending new converted data}';

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
        $update = $this->option('update');

        if ($update && $force) {
            error('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');

            return self::FAILURE;
        }

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

        if ($heikenAshiExists && ! $force && ! $update) {
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
        if ($update && ! $heikenAshiExists) {
            info('Heiken-Ashi file does not exist. Performing full conversion instead.');
            $update = false;
        }

        info($update ? 'Starting Heiken-Ashi incremental conversion...' : 'Starting Heiken-Ashi conversion...');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('OHLC Records', number_format($ohlcvHeader['numRecords']));
        $this->components->twoColumnDetail('Mode', $update ? 'Incremental Update' : ($force ? 'Force Overwrite' : 'Normal'));
        $this->newLine();

        try {
            $this->startProgressBar($update ? 'Incrementally converting OHLC to Heiken-Ashi...' : 'Converting OHLC to Heiken-Ashi...');

            if ($update) {
                $newRecordsCount = $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    function (int $current, int $total) {
                        $this->updateProgress($current, $total);
                    }
                );

                $this->finishProgressBar();

                if ($newRecordsCount === -1) {
                    info('Heiken-Ashi conversion completed successfully (full conversion was performed).');
                } elseif ($newRecordsCount === 0) {
                    warning('No new data to convert. Heiken-Ashi file is already up to date.');
                } else {
                    info('Heiken-Ashi incremental conversion completed successfully!');
                    $this->components->twoColumnDetail('New Candles Appended', number_format($newRecordsCount));
                }

                $heikenAshiPath = $converter->generateHeikenAshiFilePath($exchange, $market, $timeframe);
                $this->components->twoColumnDetail('File Path', $heikenAshiPath);

                return self::SUCCESS;
            }

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
