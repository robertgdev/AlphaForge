<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class AtrRenkoCommand extends Command
{
    use HasJsonOutput;
    use HasProgressBar;
    use ParsesMarketDataArgs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alphaforge:renkoAtr
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {atr_period : The ATR period for dynamic brick sizing (e.g., 14)}
        {--force : Force overwrite existing ATR-Renko file}
        {--update : Incrementally update the ATR-Renko file by appending new converted data}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to ATR-based Renko bricks using dynamic brick sizes';

    public function handle(AtrRenkoConverter $converter): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $atrPeriod = (int) $this->argument('atr_period');
        $force = $this->option('force');
        $update = $this->option('update');

        if ($update && $force) {
            return $this->outputJsonError('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');
        }

        // Validate ATR period
        if ($atrPeriod < 2) {
            return $this->outputJsonError('ATR period must be an integer of at least 2.');
        }

        try {
            // Get OHLC file info
            $ohlcvHeader = $converter->getOhlcvFileInfo($exchange, $market, $timeframe);
        } catch (StorageException $e) {
            return $this->outputJsonError("OHLC file not found: {$e->getMessage()}");
        }

        // Check if ATR-Renko file already exists
        $atrRenkoExists = $converter->atrRenkoFileExists($exchange, $market, $timeframe, $atrPeriod);

        if ($atrRenkoExists && ! $force && ! $update) {
            if ($this->jsonEnabled()) {
                return $this->outputJsonError('ATR-Renko file already exists. Use --force to overwrite or --update to append.');
            }

            $atrRenkoPath = $converter->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);
            warning('ATR-Renko file already exists for this configuration.');
            $this->components->twoColumnDetail('Existing File', $atrRenkoPath);

            $confirmed = confirm(
                'Do you want to overwrite the existing ATR-Renko file?',
                false
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                $this->debugMemory();

                return self::SUCCESS;
            }
        }

        // Display conversion summary
        if ($update && ! $atrRenkoExists) {
            if (! $this->jsonEnabled()) {
                info('ATR-Renko file does not exist. Performing full conversion instead.');
            }
            $update = false;
        }

        if (! $this->jsonEnabled()) {
            info($update ? 'Starting ATR-Renko incremental conversion...' : 'Starting ATR-Renko conversion...');
            $this->newLine();
            $this->displayMarketDataHeader($exchange, $market, $timeframe, [
                'ATR Period' => (string) $atrPeriod,
                'OHLC Records' => number_format($ohlcvHeader['numRecords']),
                'Mode' => $update ? 'Incremental Update' : ($force ? 'Force Overwrite' : 'Normal'),
            ]);
        }

        try {
            if (! $this->jsonEnabled()) {
                $this->startProgressBar($update ? 'Incrementally converting OHLC to ATR-Renko...' : 'Converting OHLC to ATR-Renko...');
            }

            if ($update) {
                $newRecordsCount = $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    $atrPeriod,
                    function (int $current, int $total) {
                        if (! $this->jsonEnabled()) {
                            $this->updateProgress($current, $total);
                        }
                    }
                );

                if (! $this->jsonEnabled()) {
                    $this->finishProgressBar();
                }

                $atrRenkoPath = $converter->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);

                if ($this->jsonEnabled()) {
                    if ($newRecordsCount === -1) {
                        $atrRenkoHeader = $converter->readAtrRenkoHeader($atrRenkoPath);

                        return $this->outputJson(true, [
                            'exchange' => $exchange,
                            'symbol' => $market,
                            'timeframe' => $timeframe,
                            'atrPeriod' => $atrPeriod,
                            'mode' => 'full',
                            'filePath' => $atrRenkoPath,
                            'brickCount' => $atrRenkoHeader['numRecords'],
                        ]);
                    }

                    return $this->outputJson(true, [
                        'exchange' => $exchange,
                        'symbol' => $market,
                        'timeframe' => $timeframe,
                        'atrPeriod' => $atrPeriod,
                        'mode' => 'incremental',
                        'filePath' => $atrRenkoPath,
                        'brickCount' => $newRecordsCount,
                    ]);
                }

                if ($newRecordsCount === -1) {
                    info('ATR-Renko conversion completed successfully (full conversion was performed).');
                } elseif ($newRecordsCount === 0) {
                    warning('No new data to convert. ATR-Renko file is already up to date.');
                } else {
                    info('ATR-Renko incremental conversion completed successfully!');
                    $this->components->twoColumnDetail('New Bricks Appended', number_format($newRecordsCount));
                }

                $this->components->twoColumnDetail('File Path', $atrRenkoPath);

                $this->debugMemory();

                return self::SUCCESS;
            }

            $filePath = $converter->convert(
                $exchange,
                $market,
                $timeframe,
                $atrPeriod,
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
            $atrRenkoHeader = $converter->readAtrRenkoHeader($filePath);

            if ($this->jsonEnabled()) {
                return $this->outputJson(true, [
                    'exchange' => $exchange,
                    'symbol' => $market,
                    'timeframe' => $timeframe,
                    'atrPeriod' => $atrPeriod,
                    'mode' => $force ? 'overwrite' : 'normal',
                    'filePath' => $filePath,
                    'brickCount' => $atrRenkoHeader['numRecords'],
                ]);
            }

            info('ATR-Renko conversion completed successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);
            $this->components->twoColumnDetail('Renko Bricks Generated', number_format($atrRenkoHeader['numRecords']));
            $this->components->twoColumnDetail('ATR Period', (string) (int) $atrRenkoHeader['brickSize']);

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
