<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class RenkoCommand extends Command
{
    use HasJsonOutput;
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
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}
        {--debug : Show peak memory usage on exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to Renko bricks';

    public function handle(RenkoConverter $converter): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();
        $brickSize = (float) $this->argument('brick_size');
        $force = $this->option('force');
        $update = $this->option('update');

        if ($update && $force) {
            return $this->outputJsonError('Cannot use --update and --force together. --update appends to existing data; --force overwrites it.');
        }

        // Validate brick size
        if ($brickSize <= 0) {
            return $this->outputJsonError('Brick size must be a positive number.');
        }

        try {
            // Get OHLC file info
            $ohlcvHeader = $converter->getOhlcvFileInfo($exchange, $market, $timeframe);
        } catch (StorageException $e) {
            return $this->outputJsonError("OHLC file not found: {$e->getMessage()}");
        }

        // Check if Renko file already exists
        $renkoExists = $converter->renkoFileExists($exchange, $market, $timeframe, $brickSize);

        if ($renkoExists && ! $force && ! $update) {
            if ($this->jsonEnabled()) {
                return $this->outputJsonError('Renko file already exists. Use --force to overwrite or --update to append.');
            }

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
            if (! $this->jsonEnabled()) {
                info('Renko file does not exist. Performing full conversion instead.');
            }
            $update = false;
        }

        if (! $this->jsonEnabled()) {
            info($update ? 'Starting Renko incremental conversion...' : 'Starting Renko conversion...');
            $this->newLine();
            $this->displayMarketDataHeader($exchange, $market, $timeframe, [
                'Brick Size' => (string) $brickSize,
                'OHLC Records' => number_format($ohlcvHeader['numRecords']),
                'Mode' => $update ? 'Incremental Update' : ($force ? 'Force Overwrite' : 'Normal'),
            ]);
        }

        try {
            if (! $this->jsonEnabled()) {
                $this->startProgressBar($update ? 'Incrementally converting OHLC to Renko...' : 'Converting OHLC to Renko...');
            }

            if ($update) {
                $newRecordsCount = $converter->convertIncremental(
                    $exchange,
                    $market,
                    $timeframe,
                    $brickSize,
                    function (int $current, int $total) {
                        if (! $this->jsonEnabled()) {
                            $this->updateProgress($current, $total);
                        }
                    }
                );

                if (! $this->jsonEnabled()) {
                    $this->finishProgressBar();
                }

                $renkoPath = $converter->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);

                if ($this->jsonEnabled()) {
                    if ($newRecordsCount === -1) {
                        $renkoHeader = $converter->readRenkoHeader($renkoPath);

                        return $this->outputJson(true, [
                            'exchange' => $exchange,
                            'symbol' => $market,
                            'timeframe' => $timeframe,
                            'brickSize' => $brickSize,
                            'mode' => 'full',
                            'filePath' => $renkoPath,
                            'brickCount' => $renkoHeader['numRecords'],
                        ]);
                    }

                    return $this->outputJson(true, [
                        'exchange' => $exchange,
                        'symbol' => $market,
                        'timeframe' => $timeframe,
                        'brickSize' => $brickSize,
                        'mode' => 'incremental',
                        'filePath' => $renkoPath,
                        'brickCount' => $newRecordsCount,
                    ]);
                }

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
                    if (! $this->jsonEnabled()) {
                        $this->updateProgress($current, $total);
                    }
                }
            );

            if (! $this->jsonEnabled()) {
                $this->finishProgressBar();
            }

            // Read the generated file info
            $renkoHeader = $converter->readRenkoHeader($filePath);

            if ($this->jsonEnabled()) {
                return $this->outputJson(true, [
                    'exchange' => $exchange,
                    'symbol' => $market,
                    'timeframe' => $timeframe,
                    'brickSize' => $brickSize,
                    'mode' => $force ? 'overwrite' : 'normal',
                    'filePath' => $filePath,
                    'brickCount' => $renkoHeader['numRecords'],
                ]);
            }

            info('Renko conversion completed successfully!');
            $this->components->twoColumnDetail('File Path', $filePath);
            $this->components->twoColumnDetail('Renko Bricks Generated', number_format($renkoHeader['numRecords']));
            $this->components->twoColumnDetail('Brick Size', (string) $renkoHeader['brickSize']);

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
