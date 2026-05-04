<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Data\Exception\StorageException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class AtrRenkoCommand extends Command
{
    use HasProgressBar;

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
        {--force : Force overwrite existing ATR-Renko file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert OHLC market data to ATR-based Renko bricks using dynamic brick sizes';

    public function handle(AtrRenkoConverter $converter): int
    {
        // Validate trader extension availability upfront
        if (! function_exists('trader_atr')) {
            error('The PHP Trader extension is required for ATR-based Renko conversion.');
            $this->line('  Install it via: pecl install trader');

            return self::FAILURE;
        }

        $exchange = strtolower($this->argument('exchange'));
        $market = strtoupper($this->argument('market'));
        $timeframe = $this->argument('timeframe');
        $atrPeriod = (int) $this->argument('atr_period');
        $force = $this->option('force');

        // Validate ATR period
        if ($atrPeriod < 2) {
            error('ATR period must be an integer of at least 2.');

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

        // Check if ATR-Renko file already exists
        $atrRenkoExists = $converter->atrRenkoFileExists($exchange, $market, $timeframe, $atrPeriod);

        if ($atrRenkoExists && ! $force) {
            $atrRenkoPath = $converter->generateAtrRenkoFilePath($exchange, $market, $timeframe, $atrPeriod);
            warning('ATR-Renko file already exists for this configuration.');
            $this->components->twoColumnDetail('Existing File', $atrRenkoPath);

            $confirmed = confirm(
                'Do you want to overwrite the existing ATR-Renko file?',
                false
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        // Display conversion summary
        info('Starting ATR-Renko conversion...');
        $this->newLine();
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);
        $this->components->twoColumnDetail('ATR Period', (string) $atrPeriod);
        $this->components->twoColumnDetail('OHLC Records', number_format($ohlcvHeader['numRecords']));
        $this->components->twoColumnDetail('Force Overwrite', $force ? 'Yes' : 'No');
        $this->newLine();

        try {
            $this->startProgressBar('Converting OHLC to ATR-Renko...');

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
