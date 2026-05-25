<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;
use function Safe\json_encode;

class RunBacktestCommand extends Command
{
    use HasProgressBar;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alphaforge:backtest:run
        {strategy : The strategy alias (e.g., sma_crossover, rsi_strategy)}
        {symbols* : Trading symbols to backtest (e.g., BTCUSDT ETHUSDT)}
        {--exchange=binance : Exchange identifier}
        {--timeframe=1h : Timeframe (1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M)}
        {--execution-timeframe= : Lower timeframe for order/position execution (e.g., 1m, 5m)}
        {--data-type=ohlcv : Market data type to backtest against (ohlcv, heikenashi, renko, atr_renko)}
        {--brick-size= : Brick size for renko data-type (e.g., 0.001, 10, 100)}
        {--atr-period= : ATR period for atr_renko data-type (e.g., 14)}
        {--capital=10000 : Initial capital in quote currency}
        {--stake-currency=USDT : Stake currency}
        {--start-date= : Start date (Y-m-d or Y-m-d H:i:s)}
        {--end-date= : End date (Y-m-d or Y-m-d H:i:s)}
        {--inputs= : Strategy inputs as JSON string (e.g., \'{"fastPeriod":10,"slowPeriod":50}\')}
        {--async : Queue the backtest instead of running synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a strategy backtest from the command line';

    /**
     * Execute the console command.
     */
    public function handle(
        BacktestRunService $backtestRunService,
        BacktestResultFormatter $resultFormatter,
        DateParsingService $dateParsingService,
        StrategyInputParser $inputParser,
        StrategyRegistryInterface $strategyRegistry
    ): int {
        $strategyAlias = $this->argument('strategy');
        $symbols = $this->argument('symbols');
        $exchange = $this->option('exchange');
        $timeframeValue = $this->option('timeframe');
        $executionTimeframeValue = $this->option('execution-timeframe');
        $capital = (float) $this->option('capital');
        $stakeCurrency = $this->option('stake-currency');
        $startDate = $this->option('start-date');
        $endDate = $this->option('end-date');
        $dataTypeValue = $this->option('data-type');
        $brickSize = $this->option('brick-size');
        $atrPeriod = $this->option('atr-period');
        $async = $this->option('async');
        $inputsJson = $this->option('inputs');

        // Validate strategy exists
        if (! $strategyRegistry->has($strategyAlias)) {
            $availableStrategies = array_keys($strategyRegistry->all());
            error("Strategy '{$strategyAlias}' not found.");

            if (! empty($availableStrategies)) {
                note('Available strategies: '.implode(', ', $availableStrategies));
            }

            return self::FAILURE;
        }

        // Validate data-type
        $validDataTypes = ['ohlcv', 'heikenashi', 'renko', 'atr_renko'];
        if (! in_array($dataTypeValue, $validDataTypes, true)) {
            error("Invalid data-type '{$dataTypeValue}'. Valid values: ".implode(', ', $validDataTypes));

            return self::FAILURE;
        }

        // Validate brick-size for renko
        if ($dataTypeValue === 'renko') {
            if ($brickSize === null || ! is_numeric($brickSize) || (float) $brickSize <= 0) {
                error('data-type=renko requires --brick-size with a positive numeric value (e.g., 0.001, 10, 100).');

                return self::FAILURE;
            }
        } elseif ($dataTypeValue === 'atr_renko') {
            if ($atrPeriod === null || ! is_numeric($atrPeriod) || (int) $atrPeriod <= 0) {
                error('data-type=atr_renko requires --atr-period with a positive integer value (e.g., 14).');

                return self::FAILURE;
            }
        } else {
            if ($brickSize !== null) {
                warning('--brick-size is ignored for data-type '.$dataTypeValue);
            }
            if ($atrPeriod !== null) {
                warning('--atr-period is ignored for data-type '.$dataTypeValue);
            }
        }

        // Parse timeframe
        $timeframe = $this->parseTimeframe($timeframeValue);
        if ($timeframe === null) {
            error("Invalid timeframe '{$timeframeValue}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M");

            return self::FAILURE;
        }

        // Parse execution timeframe
        $executionTimeframe = null;
        if ($executionTimeframeValue) {
            $executionTimeframe = $this->parseTimeframe($executionTimeframeValue);
            if ($executionTimeframe === null) {
                error("Invalid execution timeframe '{$executionTimeframeValue}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M");

                return self::FAILURE;
            }

            // Validate execution timeframe is lower than signal timeframe
            if ($executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
                error("Execution timeframe ({$executionTimeframe->value}) must be lower (finer granularity) than the signal timeframe ({$timeframe->value}).");

                return self::FAILURE;
            }
        }

        // Parse strategy inputs
        $inputs = $inputParser->parseInputs($inputsJson);
        if ($inputs === false) {
            error('Invalid JSON format for --inputs. Example: \'{"fastPeriod":10,"slowPeriod":50}\'');

            return self::FAILURE;
        }

        // Parse dates
        $parsedStartDate = null;
        $parsedEndDate = null;

        if ($startDate) {
            try {
                $parsedStartDate = $dateParsingService->parseDate($startDate);
            } catch (\InvalidArgumentException $e) {
                error("Invalid start-date format: {$startDate}. Use Y-m-d or Y-m-d H:i:s format.");

                return self::FAILURE;
            }
        }

        if ($endDate) {
            try {
                $parsedEndDate = $dateParsingService->parseDate($endDate);
            } catch (\InvalidArgumentException $e) {
                error("Invalid end-date format: {$endDate}. Use Y-m-d or Y-m-d H:i:s format.");

                return self::FAILURE;
            }
        }

        // Validate date range
        if ($parsedStartDate && $parsedEndDate && $parsedStartDate->greaterThanOrEqualTo($parsedEndDate)) {
            error('Start date must be before end date.');

            return self::FAILURE;
        }

        // Prepare backtest data
        $data = [
            'strategy' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'execution_timeframe' => $executionTimeframe?->value,
            'exchange' => $exchange,
            'initial_capital' => $capital,
            'stake_currency' => $stakeCurrency,
            'strategy_inputs' => $inputs,
            'start_date' => $parsedStartDate?->format('Y-m-d H:i:s'),
            'end_date' => $parsedEndDate?->format('Y-m-d H:i:s'),
            'data_type' => $dataTypeValue,
            'brick_size' => $dataTypeValue === 'renko' ? (float) $brickSize : null,
            'atr_period' => $dataTypeValue === 'atr_renko' ? (int) $atrPeriod : null,
        ];

        // Display backtest configuration
        $this->displayConfiguration($strategyAlias, $symbols, $exchange, $timeframe, $executionTimeframe, $capital, $stakeCurrency, $inputs, $dataTypeValue, $dataTypeValue === 'renko' ? (float) $brickSize : null, $dataTypeValue === 'atr_renko' ? (int) $atrPeriod : null);

        if ($async) {
            return $this->queueBacktest($backtestRunService, $data);
        }

        return $this->runBacktestSync($backtestRunService, $resultFormatter, $data);
    }

    /**
     * Run the backtest synchronously.
     */
    private function runBacktestSync(BacktestRunService $service, BacktestResultFormatter $formatter, array $data): int
    {
        info('Running backtest synchronously...');
        $this->newLine();

        try {
            $this->startProgressBar('Running backtest...');

            $result = $service->runSync($data, function (int $current, int $total, string $message) {
                $this->updateProgress($current, $total, $message);
            });

            $this->finishProgressBar();

            $this->displayResults($result, $formatter);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->finishProgressBarOnError();
            error('Backtest failed: '.$e->getMessage());
            if (! str_contains($e->getMessage(), 'Market data file not found')) {
                dump($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Queue the backtest for async execution.
     */
    private function queueBacktest(BacktestRunService $service, array $data): int
    {
        try {
            $backtestRun = $service->queue($data);

            info('Backtest queued successfully!');
            $this->newLine();
            $this->components->twoColumnDetail('Backtest ID', $backtestRun->id);
            $this->components->twoColumnDetail('Status', 'pending');
            $this->components->twoColumnDetail('Mode', 'async (queue)');
            $this->newLine();
            note('Check status via: php artisan alphaforge:backtest:list');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error('Failed to queue backtest: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Display the backtest configuration.
     */
    private function displayConfiguration(
        string $strategyAlias,
        array $symbols,
        string $exchange,
        TimeframeEnum $timeframe,
        ?TimeframeEnum $executionTimeframe,
        float $capital,
        string $stakeCurrency,
        array $inputs,
        string $dataType,
        ?float $brickSize,
        ?int $atrPeriod
    ): void {
        info('Backtest Configuration');
        $this->newLine();

        $this->components->twoColumnDetail('Strategy', $strategyAlias);
        $this->components->twoColumnDetail('Symbols', implode(', ', $symbols));
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Timeframe', $timeframe->value);
        $this->components->twoColumnDetail('Data Type', $dataType);

        if ($brickSize !== null) {
            $this->components->twoColumnDetail('Brick Size', (string) $brickSize);
        }

        if ($atrPeriod !== null) {
            $this->components->twoColumnDetail('ATR Period', (string) $atrPeriod);
        }

        if ($executionTimeframe !== null) {
            $this->components->twoColumnDetail('Execution Timeframe', $executionTimeframe->value);
        }

        $this->components->twoColumnDetail('Initial Capital', number_format($capital, 2).' '.$stakeCurrency);

        if (! empty($inputs)) {
            $this->components->twoColumnDetail('Strategy Inputs', json_encode($inputs));
        }

        $this->newLine();
    }

    /**
     * Display the backtest results.
     *
     * @param  array{backtest_run_id?: string, final_capital: float|int|string, initial_capital: float|int|string, execution_timeframe?: string|null, statistics?: array<string, mixed>, positions?: array<object|array<string, mixed>>}  $result
     */
    private function displayResults(array $result, BacktestResultFormatter $formatter): void
    {
        info('Backtest Results');
        $this->newLine();

        $summary = $formatter->formatCapitalSummary($result);

        $this->components->twoColumnDetail('Initial Capital', $summary['initial_capital']);
        $this->components->twoColumnDetail('Final Capital', $summary['final_capital']);

        if ($summary['execution_timeframe'] !== null) {
            $this->components->twoColumnDetail('Execution Timeframe', $summary['execution_timeframe']);
        }

        $pnl = $summary['pnl'];
        $this->components->twoColumnDetail('Profit/Loss', "{$pnl['color']}{$pnl['absolute']} ({$pnl['percent']}%)</>");

        $this->newLine();

        if (isset($result['statistics']) && is_array($result['statistics'])) {
            $formattedStats = $formatter->formatStatistics($result['statistics']);

            $this->line('<fg=yellow>Statistics:</>');

            foreach ($formattedStats as $label => $value) {
                $this->components->twoColumnDetail("  {$label}", $value);
            }

            $this->newLine();
        }

        if (isset($result['positions']) && is_array($result['positions']) && count($result['positions']) > 0) {
            $closedPositions = array_filter($result['positions'], fn ($p) => (is_object($p) && isset($p->exitTime) && $p->exitTime !== null) || (is_array($p) && isset($p['exitTime']) && $p['exitTime'] !== null));

            if (! empty($closedPositions)) {
                $this->line('<fg=yellow>Positions (Closed):</>');
                $positionData = $formatter->formatPositions($closedPositions, (float) $result['initial_capital']);

                table(['Symbol', 'Direction', 'Entry Price', 'Exit Price', 'PnL', 'Balance', 'CloseReason'], $positionData);
                $this->newLine();
            }
        }

        $runId = $result['backtest_run_id'] ?? 'unknown';
        note("Full results saved to database with backtest run ID: {$runId}.");
    }

    /**
     * Parse timeframe string to enum.
     */
    private function parseTimeframe(string $value): ?TimeframeEnum
    {
        return TimeframeEnum::tryFrom($value);
    }
}
