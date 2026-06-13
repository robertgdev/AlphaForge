<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Commands\Concerns\DebugMemory;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class RenkoCommand extends Command
{
    use DebugMemory;
    use HasProgressBar;
    use ParsesMarketDataArgs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alphaforge:renko
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {brick_size : The brick size for Renko conversion (e.g., 0.001, 10, 100)}
        {--force : Force overwrite existing Renko file}
        {--update : Incrementally update the Renko file by appending new converted data}
        {--debug : Show peak memory usage on exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to Renko bricks';

    public function handle(RenkoConverter $converter): int
    {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $brickSize = (float) $this->argument('brick_size');
        $force = $this->option('force');
        $update = $this->option('update');

        if ($update && $force) {
            error('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');

            $this->debugMemory();

            return self::FAILURE;
        }

        // Validate brick size
        if ($brickSize <= 0) {
            error('Brick size must be a positive number.');

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

        // Check if Renko file already exists
        $renkoExists = $converter->renkoFileExists($exchange, $market, $timeframe, $brickSize);

        if ($renkoExists && ! $force && ! $update) {
            $renkoPath = $converter->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);
            warning('Renko file already exists for this configuration.');
            $this->components->twoColumnDetail('Existing File', $renkoPath);

            $confirmed = confirm(
                'Do you want to overwrite the existing Renko file?',
                false
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                $this->debugMemory();

                return self::SUCCESS;
            }
        }

        // Display conversion summary
        if ($update && ! $renkoExists) {
            info('Renko file does not exist. Performing full conversion instead.');
            $update = false;
        }

        info($update ? 'Starting Renko incremental conversion...' : 'Starting Renko conversion...');
        $this->newLine();
        $this->displayMarketDataHeader($exchange, $market, $timeframe, [
            'Brick Size' => (string) $brickSize,
            'OHLC Records' => number_format($ohlcvHeader['numRecords']),
            'Mode' => $update ? 'Incremental Update' : ($force ? 'Force Overwrite' : 'Normal'),
        ]);

        try {
            $this->startProgressBar($update ? 'Incrementally converting OHLC to Renko...' : 'Converting OHLC to Renko...');

            if ($update) {
                $newRecordsCount = $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    $brickSize,
                    function (int $current, int $total) {
                        $this->updateProgress($current, $total);
                    }
                );

                $this->finishProgressBar();

                if ($newRecordsCount === -1) {
                    info('Renko conversion completed successfully (full conversion was performed).');
                } elseif ($newRecordsCount === 0) {
                    warning('No new data to convert. Renko file is already up to date.');
                } else {
                    info('Renko incremental conversion completed successfully!');
                    $this->components->twoColumnDetail('New Bricks Appended', number_format($newRecordsCount));
                }

                $renkoPath = $converter->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);
                $this->components->twoColumnDetail('File Path', $renkoPath);

                $this->debugMemory();

                return self::SUCCESS;
            }

            $filePath = $converter->convert(
                $exchange,
                $market,
                $timeframe,
                $brickSize,
                function (int $current, int $total) {
                    $this->updateProgress($current, $total);
                }
            );

            $this->finishProgressBar();

            // Read the generated file info
            $renkoHeader = $converter->readRenkoHeader($filePath);

            info('Renko conversion completed successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);
            $this->components->twoColumnDetail('Renko Bricks Generated', number_format($renkoHeader['numRecords']));
            $this->components->twoColumnDetail('Brick Size', (string) $renkoHeader['brickSize']);

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
