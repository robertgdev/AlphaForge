<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class RenkoCommand extends Command
{
    use HasProgressBar;
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
        {--force : Force overwrite existing Renko file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to Renko bricks';

    public function handle(RenkoConverter $converter): int
    {
        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');
        $brickSize = (float) $this->argument('brick_size');
        $force = $this->option('force');

        // Validate brick size
        if ($brickSize <= 0) {
            error('Brick size must be a positive number.');

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

        // Check if Renko file already exists
        $renkoExists = $converter->renkoFileExists($exchange, $market, $timeframe, $brickSize);

        if ($renkoExists && ! $force) {
            $renkoPath = $converter->generateRenkoFilePath($exchange, $market, $timeframe, $brickSize);
            warning('Renko file already exists for this configuration.');
            $this->components->twoColumnDetail('Existing File', $renkoPath);

            $confirmed = confirm(
                'Do you want to overwrite the existing Renko file?',
                false
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        // Display conversion summary
        info('Starting Renko conversion...');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('Brick Size', (string) $brickSize);
        $this->components->twoColumnDetail('OHLC Records', number_format($ohlcvHeader['numRecords']));
        $this->components->twoColumnDetail('Force Overwrite', $force ? 'Yes' : 'No');
        $this->newLine();

        try {
            $this->startProgressBar('Converting OHLC to Renko...');

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
