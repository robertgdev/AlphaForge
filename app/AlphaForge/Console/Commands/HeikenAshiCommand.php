<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class HeikenAshiCommand extends Command
{
    use HasJsonOutput;
    use HasProgressBar;
    use ParsesMarketDataArgs;

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
        {--update : Incrementally update the Heiken-Ashi file by appending new converted data}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to Heiken-Ashi candles';

    public function handle(HeikenAshiConverter $converter): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $force = $this->option('force');
        $update = $this->option('update');

        if ($update && $force) {
            return $this->outputJsonError('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');
        }

        try {
            // Get OHLC file info
            $ohlcvHeader = $converter->getOhlcvFileInfo($exchange, $market, $timeframe);
        } catch (StorageException $e) {
            return $this->outputJsonError("OHLC file not found: {$e->getMessage()}");
        }

        // Check if Heiken-Ashi file already exists
        $heikenAshiExists = $converter->heikenAshiFileExists($exchange, $market, $timeframe);

        if ($heikenAshiExists && ! $force && ! $update) {
            if ($this->jsonEnabled()) {
                return $this->outputJsonError('Heiken-Ashi file already exists. Use --force to overwrite or --update to append.');
            }

            $heikenAshiPath = $converter->generateHeikenAshiFilePath($exchange, $market, $timeframe);
            warning('Heiken-Ashi file already exists for this configuration.');
            $this->components->twoColumnDetail('Existing File', $heikenAshiPath);

            $confirmed = confirm(
                'Do you want to overwrite the existing Heiken-Ashi file?',
                false
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                $this->debugMemory();

                return self::SUCCESS;
            }
        }

        // Display conversion summary
        if ($update && ! $heikenAshiExists) {
            if (! $this->jsonEnabled()) {
                info('Heiken-Ashi file does not exist. Performing full conversion instead.');
            }
            $update = false;
        }

        if (! $this->jsonEnabled()) {
            info($update ? 'Starting Heiken-Ashi incremental conversion...' : 'Starting Heiken-Ashi conversion...');
            $this->newLine();
            $this->displayMarketDataHeader($exchange, $market, $timeframe, [
                'OHLC Records' => number_format($ohlcvHeader['numRecords']),
                'Mode' => $update ? 'Incremental Update' : ($force ? 'Force Overwrite' : 'Normal'),
            ]);
        }

        try {
            if (! $this->jsonEnabled()) {
                $this->startProgressBar($update ? 'Incrementally converting OHLC to Heiken-Ashi...' : 'Converting OHLC to Heiken-Ashi...');
            }

            if ($update) {
                $newRecordsCount = $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    function (int $current, int $total) {
                        if (! $this->jsonEnabled()) {
                            $this->updateProgress($current, $total);
                        }
                    }
                );

                if (! $this->jsonEnabled()) {
                    $this->finishProgressBar();
                }

                $heikenAshiPath = $converter->generateHeikenAshiFilePath($exchange, $market, $timeframe);

                if ($this->jsonEnabled()) {
                    if ($newRecordsCount === -1) {
                        $heikenAshiHeader = $converter->readHeikenAshiHeader($heikenAshiPath);

                        return $this->outputJson(true, [
                            'exchange' => $exchange,
                            'symbol' => $market,
                            'timeframe' => $timeframe,
                            'mode' => 'full',
                            'filePath' => $heikenAshiPath,
                            'candleCount' => $heikenAshiHeader['numRecords'],
                        ]);
                    }

                    return $this->outputJson(true, [
                        'exchange' => $exchange,
                        'symbol' => $market,
                        'timeframe' => $timeframe,
                        'mode' => 'incremental',
                        'filePath' => $heikenAshiPath,
                        'candleCount' => $newRecordsCount,
                    ]);
                }

                if ($newRecordsCount === -1) {
                    info('Heiken-Ashi conversion completed successfully (full conversion was performed).');
                } elseif ($newRecordsCount === 0) {
                    warning('No new data to convert. Heiken-Ashi file is already up to date.');
                } else {
                    info('Heiken-Ashi incremental conversion completed successfully!');
                    $this->components->twoColumnDetail('New Candles Appended', number_format($newRecordsCount));
                }

                $this->components->twoColumnDetail('File Path', $heikenAshiPath);

                $this->debugMemory();

                return self::SUCCESS;
            }

            $filePath = $converter->convert(
                $exchange,
                $market,
                $timeframe,
                function (int $current, int $total) {
                    if (! $this->jsonEnabled()) {
                        $this->updateProgress($current, $total);
                    }
                }
            );

            if (! $this->jsonEnabled()) {
                $this->finishProgressBar();
            }

            // Read the generated file info
            $heikenAshiHeader = $converter->readHeikenAshiHeader($filePath);

            if ($this->jsonEnabled()) {
                return $this->outputJson(true, [
                    'exchange' => $exchange,
                    'symbol' => $market,
                    'timeframe' => $timeframe,
                    'mode' => $force ? 'overwrite' : 'normal',
                    'filePath' => $filePath,
                    'candleCount' => $heikenAshiHeader['numRecords'],
                ]);
            }

            info('Heiken-Ashi conversion completed successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);
            $this->components->twoColumnDetail('Heiken-Ashi Candles Generated', number_format($heikenAshiHeader['numRecords']));

            $this->debugMemory();

            return self::SUCCESS;
        } catch (StorageException $e) {
            if (! $this->jsonEnabled()) {
                $this->finishProgressBarOnError();
            }

            return $this->outputJsonError("Conversion failed: {$e->getMessage()}");
        } catch (\Throwable $e) {
            if (! $this->jsonEnabled()) {
                $this->finishProgressBarOnError();
            }

            return $this->outputJsonError("Unexpected error: {$e->getMessage()}");
        }
    }
}
