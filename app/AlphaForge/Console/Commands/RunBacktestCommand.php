<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Dto\DataTypeConfig;
use App\AlphaForge\Services\DataAutoGenerator;
use App\AlphaForge\Backtesting\Service\BacktestResultFormatter;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Console\Concerns\HasProgressBar;
use App\AlphaForge\Strategy\Service\StrategyInputParser;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
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
        {--no-color : Disable colored output in the positions table}
        {--async : Queue the backtest instead of running synchronously}
        {--auto-generate : Auto-generate derived data files (renko, heikenashi, atr_renko, aggregated OHLCV)}
        {--force : Overwrite existing completed backtest with same parameters}
        {--trades=5 : Number of trades to display in terminal (0=none, all=all, default=5)}';

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
        StrategyRegistryInterface $strategyRegistry,
        DataAutoGenerator $dataAutoGenerator
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
        $noColor = $this->option('no-color');
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
        try {
            $dataTypeConfig = DataTypeConfig::fromOptions($dataTypeValue, $brickSize, $atrPeriod);
        } catch (\InvalidArgumentException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($dataTypeConfig->warnings as $warning) {
            warning($warning);
        }


        // Auto-generate derived data when --auto-generate is set
        if ($this->option('auto-generate')) {
            $symbolForGen = $symbols[0];
            $this->line("Auto-generate enabled — checking derived data for {$symbolForGen} / {$timeframeValue}...");

            $genResult = $dataAutoGenerator->autoGenerate(
                $dataTypeConfig,
                $exchange,
                $symbolForGen,
                $timeframeValue,
                $executionTimeframeValue,
                additionalTimeframes: [],
                output: fn (string $msg) => $this->line("  {$msg}"),
            );

            foreach ($genResult['generated'] as $path) {
                $this->line("  Generated: {$path}");
            }

            foreach ($genResult['errors'] as $err) {
                error($err);
            }

            if (! empty($genResult['errors'])) {
                return self::FAILURE;
            }

            $this->newLine();
        }

        $dataTypeValue = $dataTypeConfig->dataType;

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
            'data_type' => $dataTypeConfig->dataType,
            'brick_size' => $dataTypeConfig->brickSize,
            'atr_period' => $dataTypeConfig->atrPeriod,
        ];

        $force = $this->option('force');

        $existing = $backtestRunService->findCompletedDuplicate($data);
        if ($existing !== null) {
            if (! $force) {
                warning("A completed backtest with the same parameters already exists (ID: {$existing->id}).");
                note('Use --force to overwrite it and run a new backtest.');

                return self::FAILURE;
            }

            info("Overwriting existing completed backtest (ID: {$existing->id})...");
            $existing->delete();
        }

        // Display backtest configuration
        $this->displayConfiguration($strategyAlias, $symbols, $exchange, $timeframe, $executionTimeframe, $capital, $stakeCurrency, $inputs, $dataTypeConfig->dataType, $dataTypeConfig->brickSize, $dataTypeConfig->atrPeriod);

        if ($async) {
            return $this->queueBacktest($backtestRunService, $data);
        }

        return $this->runBacktestSync($backtestRunService, $resultFormatter, $data, $noColor, $this->option('trades'));
    }

    /**
     * Run the backtest synchronously.
     */
    private function runBacktestSync(BacktestRunService $service, BacktestResultFormatter $formatter, array $data, bool $noColor = false, string $tradesOption = '5'): int
    {
        info('Running backtest synchronously...');
        $this->newLine();

        try {
            $this->startProgressBar('Running backtest...');

            $result = $service->runSync($data, function (int $current, int $total, string $message) {
                $this->updateProgress($current, $total, $message);
            });

            $this->finishProgressBar();

            $this->displayResults($result, $formatter, $noColor, $tradesOption);

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
    private function displayResults(array $result, BacktestResultFormatter $formatter, bool $noColor = false, string $tradesOption = '5'): void
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

            $tradeDist = $formatter->formatTradeDistribution($result['statistics'], $result['timeframe'] ?? null);
            if (! empty($tradeDist)) {
                $this->line('<fg=yellow>Trade Distribution:</>');
                $this->line('  '.str_repeat('─', 40));

                foreach ($tradeDist as $label => $value) {
                    $this->components->twoColumnDetail("  {$label}", $value);
                }

                $this->newLine();
            }

            $positions = is_array($result['positions'] ?? null) ? $result['positions'] : [];
            $exitDist = $formatter->formatExitReasonDistribution($positions);
            if (! empty($exitDist)) {
                $this->line('<fg=yellow>Exit Reason Distribution:</>');
                $this->line('  '.str_repeat('─', 40));

                foreach ($exitDist as $item) {
                    $line = sprintf('%s %d (%.1f%%)',
                        str_pad($item['label'], 22),
                        $item['count'],
                        $item['pct']
                    );
                    $this->line("  {$line}");
                }

                $this->newLine();
            }
        }

        if (isset($result['positions']) && is_array($result['positions']) && count($result['positions']) > 0) {
            $closedPositions = array_filter($result['positions'], fn ($p) => (is_object($p) && isset($p->exitTime) && $p->exitTime !== null) || (is_array($p) && isset($p['exitTime']) && $p['exitTime'] !== null));

            if (! empty($closedPositions)) {
                $totalClosed = count($closedPositions);

                if ($tradesOption === 'none' || $tradesOption === '0') {
                    $this->line("<fg=yellow>Positions (Closed):</> {$totalClosed} trades omitted.");
                    $this->line('  Use <fg=gray>alphaforge:export:backtest '.($result['backtest_run_id'] ?? '{id}').'</> to export all trades.');
                    $this->newLine();
                } else {
                    $limit = $tradesOption === 'all' ? $totalClosed : min((int) $tradesOption, $totalClosed);
                    $displayPositions = array_slice(array_values($closedPositions), 0, $limit);
                    $positionData = $formatter->formatPositions($displayPositions, (float) $result['initial_capital'], $noColor);

                    $this->line("<fg=yellow>Positions (Closed):</> {$totalClosed} trades");

                    $table = new Table($this->output);
                    $table->setHeaders(['Symbol', 'Direction', 'Entry Time', 'Exit Time', 'Duration', 'Entry Price', 'Exit Price', 'PnL', 'Balance', 'CloseReason']);
                    $table->setRows($positionData);

                    $rightAlign = new TableStyle;
                    $rightAlign->setPadType(STR_PAD_LEFT);
                    foreach ([4, 5, 6, 7, 8] as $colIndex) {
                        $table->setColumnStyle($colIndex, $rightAlign);
                    }

                    $table->render();

                    if ($limit < $totalClosed) {
                        $this->line('  ... and '.($totalClosed - $limit).' more trades (use <fg=gray>alphaforge:export:backtest '.($result['backtest_run_id'] ?? '{id}').'</> to export all)');
                    }

                    $this->newLine();
                }
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
