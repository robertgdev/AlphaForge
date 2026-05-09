<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;

use function Safe\json_encode;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;


class RunBacktestCommand extends Command
{
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
        $inputsJson = $this->option('inputs');
        $async = $this->option('async');

        // Validate strategy exists
        if (! $strategyRegistry->has($strategyAlias)) {
            $availableStrategies = array_keys($strategyRegistry->all());
            error("Strategy '{$strategyAlias}' not found.");

            if (! empty($availableStrategies)) {
                note('Available strategies: '.implode(', ', $availableStrategies));
            }

            return self::FAILURE;
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
        ];

        // Display backtest configuration
        $this->displayConfiguration($strategyAlias, $symbols, $exchange, $timeframe, $executionTimeframe, $capital, $stakeCurrency, $inputs);

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
            $result = $service->runSync($data);

            $this->displayResults($result, $formatter);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error('Backtest failed: '.$e->getMessage());
            dump($e->getTraceAsString());

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
        array $inputs
    ): void {
        info('Backtest Configuration');
        $this->newLine();

        $this->components->twoColumnDetail('Strategy', $strategyAlias);
        $this->components->twoColumnDetail('Symbols', implode(', ', $symbols));
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Timeframe', $timeframe->value);

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
     */
    private function displayResults(array $result, BacktestResultFormatter $formatter): void
    {
        info('Backtest Results');
        $this->newLine();

        $summary = $formatter->formatCapitalSummary($result);

        $this->components->twoColumnDetail('Final Capital', $summary['final_capital']);
        $this->components->twoColumnDetail('Initial Capital', $summary['initial_capital']);

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
            $closedPositions = array_filter($result['positions'], fn ($p) => $p->exitTime !== null);

            if (! empty($closedPositions)) {
                $this->line('<fg=yellow>Positions (Closed):</>');
                $positionData = $formatter->formatPositions($closedPositions);

                table(['Symbol', 'Direction', 'Entry Price', 'Exit Price', 'PnL'], $positionData);
                $this->newLine();
            }
        }

        note('Full results saved to database with backtest run ID.');
    }

    /**
     * Parse timeframe string to enum.
     */
    private function parseTimeframe(string $value): ?TimeframeEnum
    {
        return TimeframeEnum::tryFrom($value);
    }
}
