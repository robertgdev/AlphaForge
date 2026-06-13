<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Console\Commands\Concerns\DebugMemory;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class AtrRenkoCommand extends Command
{
    use HasProgressBar;
    use ParsesMarketDataArgs;
    use DebugMemory;

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
        {--debug : Show peak memory usage on exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to ATR-based Renko bricks using dynamic brick sizes';

    public function handle(AtrRenkoConverter $converter): int
    {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $atrPeriod = (int) $this->argument('atr_period');
        $force = $this->option('force');
        $update = $this->option('update');

        if ($update && $force) {
            error('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');

            $this->debugMemory();
            return self::FAILURE;
        }

        // Validate ATR period
        if ($atrPeriod < 2) {
            error('ATR period must be an integer of at least 2.');

            $this->debugMemory();
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

            $this->debugMemory();
            return self::FAILURE;
        }

        // Check if ATR-Renko file already exists
        $atrRenkoExists = $converter->atrRenkoFileExists($exchange, $market, $timeframe, $atrPeriod);

        if ($atrRenkoExists && ! $force && ! $update) {
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
            info('ATR-Renko file does not exist. Performing full conversion instead.');
            $update = false;
        }

        info($update ? 'Starting ATR-Renko incremental conversion...' : 'Starting ATR-Renko conversion...');
        $this->newLine();
        $this->displayMarketDataHeader($exchange, $market, $timeframe, [
            'ATR Period' => (string) $atrPeriod,
            'OHLC Records' => number_format($ohlcvHeader['numRecords']),
            'Mode' => $update ? 'Incremental Update' : ($force ? 'Force Overwrite' : 'Normal'),
        ]);

        try {
            $this->startProgressBar($update ? 'Incrementally converting OHLC to ATR-Renko...' : 'Converting OHLC to ATR-Renko...');

            if ($update) {
                $newRecordsCount = $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    $atrPeriod,
                    function (int $current, int $total) {
                        $this->updateProgress($current, $total);
                    }
                );

                $this->finishProgressBar();

                if ($newRecordsCount === -1) {
                    info('ATR-Renko conversion completed successfully (full conversion was performed).');
                } elseif ($newRecordsCount === 0) {
                    warning('No new data to convert. ATR-Renko file is already up to date.');
                } else {
                    info('ATR-Renko incremental conversion completed successfully!');
                    $this->components->twoColumnDetail('New Bricks Appended', number_format($newRecordsCount));
                }

                $atrRenkoPath = $converter->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);
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
                    $this->updateProgress($current, $total);
                }
            );

            $this->finishProgressBar();

            // Read the generated file info
            $atrRenkoHeader = $converter->readAtrRenkoHeader($filePath);

            info('ATR-Renko conversion completed successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);
            $this->components->twoColumnDetail('Renko Bricks Generated', number_format($atrRenkoHeader['numRecords']));
            $this->components->twoColumnDetail('ATR Period', (string) (int) $atrRenkoHeader['brickSize']);

            $this->debugMemory();
            return self::SUCCESS;
        } catch (StorageException $e) {
            $this->finishProgressBarOnError();
            error("Conversion failed: {$e->getMessage()}");

            $this->debugMemory();
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();
            error("Unexpected error: {$e->getMessage()}");

            $this->debugMemory();
            return self::FAILURE;
        }
    }
}
