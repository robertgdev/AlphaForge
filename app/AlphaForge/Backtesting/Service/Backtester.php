<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Backtesting\Optimization\MarketDataSnapshot;
use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\ExitRule\ExitContext;
use App\AlphaForge\ExitRule\ExitRuleSet;
use App\AlphaForge\ExitRule\ExitTrigger;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Dto\PendingOrder;
use App\AlphaForge\Order\Dto\PositionDto;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Order\Model\OrderManager;
use App\AlphaForge\Order\Model\PortfolioManager;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\Dto\InitializeData;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;
use RuntimeException;

class Backtester
{
    private const BAR_T = 0;

    private const BAR_O = 1;

    private const BAR_H = 2;

    private const BAR_L = 3;

    private const BAR_C = 4;

    private const BAR_V = 5;

    private BacktestCursor $cursor;

    private OrderManager $orderManager;

    private PortfolioManager $portfolioManager;

    /** @var Vector<mixed> Positions in the backtest */
    private Vector $positions;

    /** @var Map<string, int> Map position ID to index */
    private Map $openPositionIndex;

    /** @var Map<string, OhlcvSeries> Map symbol to OHLCV series */
    private Map $ohlcvData;

    /** @var Map<string, OhlcvSeries>|null */
    private ?Map $executionOhlcvData = null;

    private ?TimeframeEnum $executionTimeframe = null;

    private ?TimeframeEnum $signalTimeframe = null;

    private ?MultiTimeframeOhlcvSeries $multiTimeframeData = null;

    private object $strategy;

    private string $initialCapital;

    private string $currentCapital;

    /** @var array<string, string> */
    private array $commissionConfig;

    /** @var array<int, array<string, mixed>> */
    private array $positionTradeDetails = [];

    /** @var callable|null */
    private $progressCallback = null;

    /** @var array<string, float> */
    private array $highWaterMarks = [];

    /** @var array<string, float> */
    private array $lowWaterMarks = [];

    /** @var array<string, int> */
    private array $barsInPositionTracker = [];

    /** @var Vector<string> Bar-level equity curve for periodic risk metrics */
    private Vector $barEquityCurve;

    /** @var array<int> Pre-extracted timestamps for O(1) bar access */
    private array $barTimestamps = [];

    /** @var array<float> Pre-extracted opens for O(1) bar access */
    private array $barOpens = [];

    /** @var array<float> Pre-extracted highs for O(1) bar access */
    private array $barHighs = [];

    /** @var array<float> Pre-extracted lows for O(1) bar access */
    private array $barLows = [];

    /** @var array<float> Pre-extracted closes for O(1) bar access */
    private array $barCloses = [];

    /** @var array<float> Pre-extracted volumes for O(1) bar access */
    private array $barVolumes = [];

    /** @var array<int>|null Pre-extracted execution timestamps */
    private ?array $execTimestamps = null;

    /** @var array<float>|null Pre-extracted execution opens */
    private ?array $execOpens = null;

    /** @var array<float>|null Pre-extracted execution highs */
    private ?array $execHighs = null;

    /** @var array<float>|null Pre-extracted execution lows */
    private ?array $execLows = null;

    /** @var array<float>|null Pre-extracted execution closes */
    private ?array $execCloses = null;

    /** @var array<float>|null Pre-extracted execution volumes */
    private ?array $execVolumes = null;

    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry,
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly StatisticsServiceInterface $statisticsService,
        private readonly SeriesMetricServiceInterface $seriesMetricService,
        private readonly MultiTimeframeDataServiceInterface $multiTimeframeDataService,
        private readonly string $marketDataPath
    ) {}

    /**
     * Run a backtest with the given configuration.
     *
     * @param  string  $strategyAlias  The strategy alias
     * @param  array  $symbols  Array of trading symbols
     * @param  TimeframeEnum  $timeframe  The primary (signal) timeframe
     * @param  string  $exchange  The exchange name
     * @param  string  $initialCapital  Starting capital
     * @param  string  $stakeCurrency  The stake currency (e.g., 'USDT')
     * @param  array  $strategyInputs  Strategy-specific inputs
     * @param  array  $commissionConfig  Commission configuration
     * @param  array  $additionalTimeframes  Additional timeframes for multi-timeframe strategies
     * @param  Carbon|null  $startDate  Optional start date filter
     * @param  Carbon|null  $endDate  Optional end date filter
     * @param  TimeframeEnum|null  $executionTimeframe  Lower timeframe for order/position execution
     * @param  callable|null  $progressCallback  Optional callback receiving (int $current, int $total, string $message)
     * @param  string  $dataType  Market data type (ohlcv, heikenashi, renko, atr_renko)
     * @param  float|null  $brickSize  Brick size for renko data type
     * @param  int|null  $atrPeriod  ATR period for atr_renko data type
     * @return array Backtest results
     */
    public function run(
        string $strategyAlias,
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        string $initialCapital,
        string $stakeCurrency,
        array $strategyInputs = [],
        array $commissionConfig = [],
        array $additionalTimeframes = [],
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?TimeframeEnum $executionTimeframe = null,
        ?callable $progressCallback = null,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): array {
        // Validate execution timeframe is lower than signal timeframe
        if ($executionTimeframe !== null && $executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
            throw new RuntimeException(
                "Execution timeframe ({$executionTimeframe->value}) must be lower (finer) than the signal timeframe ({$timeframe->value})."
            );
        }

        // Initialize
        $this->initialize($initialCapital, $commissionConfig);

        // Store progress callback
        $this->progressCallback = $progressCallback;

        $this->emitProgress(0, 100, 'Initializing...');

        // Load strategy
        $this->strategy = $this->strategyRegistry->get($strategyAlias);
        $this->configureStrategy($strategyInputs);

        // Store timeframe references
        $this->signalTimeframe = $timeframe;
        $this->executionTimeframe = $executionTimeframe;

        $this->emitProgress(10, 100, 'Loading market data...');

        // Load market data
        $this->loadMarketData($symbols, $timeframe, $exchange, $additionalTimeframes, $startDate, $endDate, $executionTimeframe, $dataType, $brickSize, $atrPeriod);

        // Initialize strategy (pre-compute indicators)
        $this->emitProgress(20, 100, 'Computing indicators...');
        $this->initializeStrategy($symbols[0], $this->ohlcvData->get($symbols[0]));

        // Run the backtest loop
        $this->emitProgress(30, 100, 'Running backtest...');
        $this->runBacktestLoop($symbols);

        // Calculate statistics
        $this->emitProgress(90, 100, 'Calculating statistics...');
        $barsPerYear = $this->computeBarsPerYear();
        $riskFreeRate = (string) config('alphaforge.backtesting.risk_free_rate', '0.02');
        $statistics = $this->statisticsService->calculate(
            $this->positions,
            $this->initialCapital,
            $this->currentCapital,
            riskFreeRate: $riskFreeRate,
            tradingDaysPerYear: $barsPerYear,
            barEquityCurve: $this->barEquityCurve,
        );
        $statistics['position_pnl_values'] = $this->extractClosedPositionPnl();
        $statistics['position_trades'] = $this->positionTradeDetails;

        $this->emitProgress(100, 100, 'Backtest completed');

        // Return (run())
        return [
            'strategy' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'execution_timeframe' => $executionTimeframe?->value,
            'exchange' => $exchange,
            'initial_capital' => $this->initialCapital,
            'final_capital' => $this->currentCapital,
            'positions' => $this->positions->toArray(),
            'statistics' => $statistics,
        ];
    }

    /**
     * Run a backtest using preloaded market data (avoids redundant file I/O).
     *
     * @param  MarketDataSnapshot  $data  Preloaded OHLCV data
     * @return array Backtest results
     */
    public function runWithPreloadedData(
        string $strategyAlias,
        array $symbols,
        TimeframeEnum $timeframe,
        string $initialCapital,
        string $stakeCurrency,
        array $strategyInputs,
        array $commissionConfig,
        array $additionalTimeframes,
        MarketDataSnapshot $data,
        ?TimeframeEnum $executionTimeframe = null,
        ?callable $progressCallback = null,
    ): array {
        if ($executionTimeframe !== null && $executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
            throw new RuntimeException(
                "Execution timeframe ({$executionTimeframe->value}) must be lower (finer) than the signal timeframe ({$timeframe->value})."
            );
        }

        $this->initialize($initialCapital, $commissionConfig);

        $this->progressCallback = $progressCallback;

        $this->emitProgress(0, 100, 'Initializing...');

        $this->strategy = $this->strategyRegistry->get($strategyAlias);
        $this->configureStrategy($strategyInputs);

        $this->signalTimeframe = $timeframe;
        $this->executionTimeframe = $executionTimeframe;

        $this->emitProgress(5, 100, 'Using preloaded market data...');

        $this->loadMarketDataFromSnapshot($symbols, $additionalTimeframes, $timeframe, $data);

        $this->emitProgress(20, 100, 'Computing indicators...');
        $this->initializeStrategy($symbols[0], $this->ohlcvData->get($symbols[0]));

        $this->emitProgress(30, 100, 'Running backtest...');
        $this->runBacktestLoop($symbols);

        $this->emitProgress(90, 100, 'Calculating statistics...');
        $barsPerYear = $this->computeBarsPerYear();
        $riskFreeRate = (string) config('alphaforge.backtesting.risk_free_rate', '0.02');
        $statistics = $this->statisticsService->calculate(
            $this->positions,
            $this->initialCapital,
            $this->currentCapital,
            riskFreeRate: $riskFreeRate,
            tradingDaysPerYear: $barsPerYear,
            barEquityCurve: $this->barEquityCurve,
        );
        $statistics['position_pnl_values'] = $this->extractClosedPositionPnl();
        $statistics['position_trades'] = $this->positionTradeDetails;

        $this->emitProgress(100, 100, 'Backtest completed');

        // Return (runWithPreloadedData)
        return [
            'strategy' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'execution_timeframe' => $executionTimeframe?->value,
            'initial_capital' => $this->initialCapital,
            'final_capital' => $this->currentCapital,
            'positions' => $this->positions->toArray(),
            'statistics' => $statistics,
        ];
    }

    /**
     * Initialize the backtester state.
     */
    private function initialize(string $initialCapital, array $commissionConfig): void
    {
        $this->initialCapital = $initialCapital;
        $this->currentCapital = $initialCapital;
        $this->commissionConfig = $commissionConfig;
        $this->cursor = new BacktestCursor;
        $this->orderManager = new OrderManager;
        $this->portfolioManager = new PortfolioManager($initialCapital);
        $this->positions = new Vector;
        $this->openPositionIndex = new Map;
        $this->ohlcvData = new Map;
        $this->executionOhlcvData = null;
        $this->executionTimeframe = null;
        $this->signalTimeframe = null;
        $this->highWaterMarks = [];
        $this->lowWaterMarks = [];
        $this->barsInPositionTracker = [];
        $this->barEquityCurve = new Vector;
        $this->positionTradeDetails = [];
        $this->progressCallback = null;
    }

    private function emitProgress(int $current, int $total, string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($current, $total, $message);
        }
    }

    /**
     * Configure the strategy with inputs.
     */
    private function configureStrategy(array $inputs): void
    {
        if (method_exists($this->strategy, 'configure')) {
            $this->strategy->configure($inputs);
        }
    }

    /**
     * Load market data for all symbols.
     */
    private function loadMarketData(
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        array $additionalTimeframes,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?TimeframeEnum $executionTimeframe,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): void {
        // Load signal timeframe data
        foreach ($symbols as $symbol) {
            $filePath = $this->getMarketDataPath($symbol, $timeframe, $exchange, $dataType, $brickSize, $atrPeriod);
            $ohlcv = $this->loadOhlcvSeries($filePath);

            // Apply date filters
            if ($startDate || $endDate) {
                $ohlcv = $this->filterByDateRange($ohlcv, $startDate, $endDate);
            }

            $this->ohlcvData->put($symbol, $ohlcv);
        }

        // Load execution timeframe data if specified
        if ($executionTimeframe !== null) {
            $this->executionOhlcvData = new Map;

            foreach ($symbols as $symbol) {
                $filePath = $this->getMarketDataPath($symbol, $executionTimeframe, $exchange, 'ohlcv');

                if (! file_exists($filePath)) {
                    throw new RuntimeException(
                        "Execution timeframe data ({$executionTimeframe->value}) not found for {$symbol} on {$exchange}. "
                        .'Download the data first or remove the execution_timeframe setting.'
                    );
                }

                $ohlcv = $this->loadOhlcvSeries($filePath);

                // Apply date filters
                if ($startDate || $endDate) {
                    $ohlcv = $this->filterByDateRange($ohlcv, $startDate, $endDate);
                }

                $this->executionOhlcvData->put($symbol, $ohlcv);
            }

            // Validate time alignment
            foreach ($symbols as $symbol) {
                $this->validateTimeAlignment(
                    $this->ohlcvData->get($symbol),
                    $this->executionOhlcvData->get($symbol),
                    $symbol
                );
            }
        }

        // Handle multi-timeframe data
        if (! empty($additionalTimeframes)) {
            $baseOhlcv = $this->ohlcvData->first()->value;
            $aggregated = $this->multiTimeframeDataService->aggregate($baseOhlcv, $additionalTimeframes);
            $this->multiTimeframeData = new MultiTimeframeOhlcvSeries(
                $baseOhlcv,
                new Map($aggregated),
                $this->cursor
            );
        }

        $this->preExtractBarArrays($symbols[0]);
    }

    /**
     * Load market data from a preloaded snapshot instead of reading binary files.
     */
    private function loadMarketDataFromSnapshot(
        array $symbols,
        array $additionalTimeframes,
        TimeframeEnum $timeframe,
        MarketDataSnapshot $snapshot,
    ): void {
        foreach ($symbols as $symbol) {
            if (! isset($snapshot->signalData[$symbol])) {
                throw new RuntimeException("Symbol {$symbol} not found in preloaded market data snapshot.");
            }

            $entry = $snapshot->signalData[$symbol];
            $ohlcv = new OhlcvSeries($entry['data'], $this->cursor, $entry['symbol'], $entry['timeframe']);
            $this->ohlcvData->put($symbol, $ohlcv);
        }

        if ($snapshot->executionData !== null) {
            $this->executionOhlcvData = new Map;

            foreach ($symbols as $symbol) {
                if (! isset($snapshot->executionData[$symbol])) {
                    throw new RuntimeException("Execution data for {$symbol} not found in preloaded market data snapshot.");
                }

                $entry = $snapshot->executionData[$symbol];
                $ohlcv = new OhlcvSeries($entry['data'], $this->cursor, $entry['symbol'], $entry['timeframe']);
                $this->executionOhlcvData->put($symbol, $ohlcv);
            }
        }

        if (! empty($additionalTimeframes)) {
            $baseOhlcv = $this->ohlcvData->first()->value;
            $aggregated = $this->multiTimeframeDataService->aggregate($baseOhlcv, $additionalTimeframes);
            $this->multiTimeframeData = new MultiTimeframeOhlcvSeries(
                $baseOhlcv,
                new Map($aggregated),
                $this->cursor
            );
        }

        $this->preExtractBarArrays($symbols[0]);
    }

    private function preExtractBarArrays(string $symbol): void
    {
        $ohlcv = $this->ohlcvData->get($symbol);
        $this->barTimestamps = $ohlcv->getTimestamps()->getVector()->toArray();
        $this->barOpens = $ohlcv->getOpens()->getVector()->toArray();
        $this->barHighs = $ohlcv->getHighs()->getVector()->toArray();
        $this->barLows = $ohlcv->getLows()->getVector()->toArray();
        $this->barCloses = $ohlcv->getCloses()->getVector()->toArray();
        $this->barVolumes = $ohlcv->getVolumes()->getVector()->toArray();

        if ($this->executionOhlcvData !== null) {
            $execOhlcv = $this->executionOhlcvData->get($symbol);
            $this->execTimestamps = $execOhlcv->getTimestamps()->getVector()->toArray();
            $this->execOpens = $execOhlcv->getOpens()->getVector()->toArray();
            $this->execHighs = $execOhlcv->getHighs()->getVector()->toArray();
            $this->execLows = $execOhlcv->getLows()->getVector()->toArray();
            $this->execCloses = $execOhlcv->getCloses()->getVector()->toArray();
            $this->execVolumes = $execOhlcv->getVolumes()->getVector()->toArray();
        }
    }

    /**
     * Validate that execution timeframe data covers the signal timeframe date range.
     */
    private function validateTimeAlignment(OhlcvSeries $signalOhlcv, OhlcvSeries $execOhlcv, string $symbol): void
    {
        $signalTimestamps = $signalOhlcv->getTimestamps();
        $execTimestamps = $execOhlcv->getTimestamps();

        if ($signalTimestamps->count() === 0 || $execTimestamps->count() === 0) {
            throw new RuntimeException(
                "No data available for time alignment validation on {$symbol}."
            );
        }

        $signalStart = $signalTimestamps->getVector()->get(0);
        $signalEnd = $signalTimestamps->getVector()->get($signalTimestamps->count() - 1);
        $execStart = $execTimestamps->getVector()->get(0);
        $execEnd = $execTimestamps->getVector()->get($execTimestamps->count() - 1);

        if ($execStart > $signalStart || $execEnd < $signalEnd) {
            throw new RuntimeException(
                "Execution timeframe data for {$symbol} does not cover the full signal timeframe date range. "
                ."Signal: {$signalStart}-{$signalEnd}, Execution: {$execStart}-{$execEnd}. "
                .'Download execution data that covers the full period.'
            );
        }
    }

    /**
     * Load OHLCV data from binary file and return an OhlcvSeries.
     */
    private function loadOhlcvSeries(string $filePath): OhlcvSeries
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Market data file not found: {$filePath}. Download the data first using alphaforge:data:import.");
        }

        $records = iterator_to_array($this->binaryStorage->readRecordsSequentially($filePath));

        if (count($records) === 0) {
            throw new RuntimeException("Market data file is empty: {$filePath}. Download the data again.");
        }

        $timestamps = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];

        foreach ($records as $record) {
            $timestamps[] = $record['timestamp'];
            $opens[] = $record['open'];
            $highs[] = $record['high'];
            $lows[] = $record['low'];
            $closes[] = $record['close'];
            $volumes[] = $record['volume'];
        }

        $marketData = [
            'timestamp' => $timestamps,
            'open' => $opens,
            'high' => $highs,
            'low' => $lows,
            'close' => $closes,
            'volume' => $volumes,
        ];

        return new OhlcvSeries($marketData, $this->cursor);
    }

    /**
     * Get the file path for market data.
     */
    private function getMarketDataPath(
        string $symbol,
        TimeframeEnum $timeframe,
        string $exchange,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): string {
        $basePath = sprintf(
            '%s/%s/%s/%s',
            $this->marketDataPath,
            $exchange,
            strtoupper($symbol),
            $timeframe->value
        );

        return match ($dataType) {
            'heikenashi' => $basePath.'/heikenashi.stchx',
            'renko' => $basePath.'/renko_'.$this->formatBrickSize($brickSize ?? 10.0).'.stchx',
            'atr_renko' => $basePath.'/renko_atr_'.($atrPeriod ?? 14).'.stchx',
            default => $basePath.'/ohlcv.stchx',
        };
    }

    /**
     * Format brick size for filename (avoiding special characters).
     */
    private function formatBrickSize(float $brickSize): string
    {
        if (floor($brickSize) === $brickSize) {
            return (string) (int) $brickSize;
        }

        return str_replace('.', '_', (string) $brickSize);
    }

    /**
     * Filter OHLCV data by date range.
     */
    private function filterByDateRange(OhlcvSeries $ohlcv, ?Carbon $startDate, ?Carbon $endDate): OhlcvSeries
    {
        $timestamps = $ohlcv->getTimestamps();
        $startIndex = 0;
        $endIndex = $timestamps->count() - 1;

        if ($startDate) {
            for ($i = 0; $i < $timestamps->count(); $i++) {
                if ($timestamps->getVector()->get($i) >= $startDate->timestamp) {
                    $startIndex = $i;
                    break;
                }
            }
        }

        if ($endDate) {
            for ($i = $timestamps->count() - 1; $i >= 0; $i--) {
                if ($timestamps->getVector()->get($i) <= $endDate->timestamp) {
                    $endIndex = $i;
                    break;
                }
            }
        }

        return $ohlcv->slice($startIndex, $endIndex - $startIndex + 1);
    }

    /**
     * Run the main backtest loop.
     *
     * When an execution timeframe is set, delegates to the dual-timeframe loop
     * which generates signals on the signal timeframe but processes orders/exits
     * at execution granularity. Otherwise, runs the standard single-timeframe loop.
     */
    private function runBacktestLoop(array $symbols): void
    {
        $primarySymbol = $symbols[0];
        $primaryOhlcv = $this->ohlcvData->get($primarySymbol);

        if ($this->executionTimeframe !== null && $this->executionOhlcvData !== null) {
            $this->runDualTimeframeLoop($primarySymbol, $primaryOhlcv);
        } else {
            $this->runSingleTimeframeLoop($primarySymbol, $primaryOhlcv);
        }

        // Close any remaining positions at the last price
        $this->closeAllPositions($primaryOhlcv);
    }

    /**
     * Run the standard single-timeframe backtest loop.
     *
     * Strategy signals, order processing, and position exits all occur
     * at the same (signal) timeframe granularity.
     */
    private function runSingleTimeframeLoop(string $primarySymbol, OhlcvSeries $primaryOhlcv): void
    {
        $totalBars = $primaryOhlcv->getTimestamps()->count();

        for ($this->cursor->currentIndex = 0; $this->cursor->currentIndex < $totalBars; $this->cursor->currentIndex++) {
            if ($this->cursor->currentIndex % 100 === 0) {
                $this->emitProgress(
                    30 + (int) round(($this->cursor->currentIndex / $totalBars) * 60),
                    100,
                    "Processing bar {$this->cursor->currentIndex}/{$totalBars}"
                );
            }

            // Get current bar data
            $currentBar = $this->getCurrentBar();

            // Process pending orders
            if ($this->orderManager->hasPendingOrders()) {
                $this->processPendingOrders($currentBar);
            }

            $hasPositions = $this->portfolioManager->hasOpenPositions();

            if ($hasPositions) {
                $this->checkPositionExits($currentBar);
                $this->updatePositionWaterMarks($currentBar);
            }

            // Call strategy for signals
            $signals = $this->callStrategy($primarySymbol, $primaryOhlcv);

            // Process signals
            $this->processSignals($signals, $currentBar, $primarySymbol);

            // Record bar-level equity
            $this->recordBarEquity($currentBar, $primarySymbol);
        }
    }

    /**
     * Run the dual-timeframe backtest loop.
     *
     * Strategy signals are generated on the signal timeframe (e.g., H1),
     * but pending orders and position exits (SL/TP) are processed at the
     * execution timeframe granularity (e.g., M1) for improved accuracy.
     *
     * The loop iterates over signal timeframe bars. For each signal bar:
     * 1. Process pending orders on M1 bars from the previous signal bar's window
     * 2. Check position exits (SL/TP) on those same M1 bars
     * 3. Call the strategy on the signal bar to generate new signals
     * 4. Process the new signals into pending orders
     *
     * This means signals generated at the close of an H1 bar will have their
     * resulting orders evaluated starting from the first M1 bar of the NEXT
     * H1 bar's time window — matching the realistic "act on next open" scenario.
     */
    private function runDualTimeframeLoop(string $symbol, OhlcvSeries $signalOhlcv): void
    {
        $executionOhlcv = $this->executionOhlcvData->get($symbol);
        $signalTimestamps = $signalOhlcv->getTimestamps();
        $execTimestamps = $executionOhlcv->getTimestamps();

        $totalSignalBars = $signalTimestamps->count();
        $totalExecBars = $execTimestamps->count();
        $signalBarDuration = $this->signalTimeframe->toSeconds();

        $this->initializeStrategy($symbol, $signalOhlcv);

        $this->initializeStrategy($symbol, $signalOhlcv);

        // Find the first execution bar index that corresponds to the start of the signal data
        $signalStart = $signalTimestamps->getVector()->get(0);
        $execStart = 0;
        for ($i = 0; $i < $totalExecBars; $i++) {
            if ($execTimestamps->getVector()->get($i) >= $signalStart) {
                $execStart = $i;
                break;
            }
        }

        $execIndex = $execStart;

        for ($signalIndex = 0; $signalIndex < $totalSignalBars; $signalIndex++) {
            $this->cursor->currentIndex = $signalIndex;

            if ($signalIndex % 100 === 0) {
                $this->emitProgress(
                    30 + (int) round(($signalIndex / $totalSignalBars) * 60),
                    100,
                    "Processing signal bar {$signalIndex}/{$totalSignalBars}"
                );
            }

            // Determine the time window for this signal bar
            $signalBarStart = $signalTimestamps->getVector()->get($signalIndex);
            $signalBarEnd = $signalBarStart + $signalBarDuration;

            // Process pending orders and position exits on execution bars within this signal bar's window
            while ($execIndex < $totalExecBars) {
                $execBarTimestamp = $execTimestamps->getVector()->get($execIndex);

                // If this execution bar is beyond the current signal bar's window, stop
                if ($execBarTimestamp >= $signalBarEnd) {
                    break;
                }

                $this->cursor->executionIndex = $execIndex;
                $execBar = $this->getExecBarByIndex($execIndex);

                // Process pending orders at execution granularity
                if ($this->orderManager->hasPendingOrders()) {
                    $this->processPendingOrders($execBar);
                }

                $hasPositions = $this->portfolioManager->hasOpenPositions();

                if ($hasPositions) {
                    // Check SL/TP at execution granularity
                    $this->checkPositionExits($execBar);
                    // Update high/low water marks for trailing stop support
                    $this->updatePositionWaterMarks($execBar);
                }

                // Record bar-level equity at execution granularity
                $this->recordBarEquity($execBar, $symbol);

                $execIndex++;
            }

            // Call strategy on signal timeframe bar to generate new signals
            $signals = $this->callStrategy($symbol, $signalOhlcv);

            // Process signals into pending orders (will be evaluated on next iteration's M1 bars)
            $signalBar = $this->getCurrentBar();
            $this->processSignals($signals, $signalBar, $symbol);
        }

        // Process any remaining execution bars up to the end of the last signal bar
        $lastSignalBarEnd = $signalTimestamps->getVector()->get($totalSignalBars - 1) + $signalBarDuration;
        while ($execIndex < $totalExecBars) {
            $execBarTimestamp = $execTimestamps->getVector()->get($execIndex);
            if ($execBarTimestamp >= $lastSignalBarEnd) {
                break;
            }

            $this->cursor->executionIndex = $execIndex;
            $execBar = $this->getExecBarByIndex($execIndex);

            if ($this->orderManager->hasPendingOrders()) {
                $this->processPendingOrders($execBar);
            }

            $hasPositions = $this->portfolioManager->hasOpenPositions();
            if ($hasPositions) {
                $this->checkPositionExits($execBar);
                $this->updatePositionWaterMarks($execBar);
            }

            $this->recordBarEquity($execBar, $symbol);

            $execIndex++;
        }
    }

    /**
     * Get the current bar data from the signal timeframe.
     *
     * @return array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}
     */
    private function getCurrentBar(): array
    {
        $i = $this->cursor->currentIndex;

        return [
            self::BAR_T => $this->barTimestamps[$i],
            self::BAR_O => (float) $this->barOpens[$i],
            self::BAR_H => (float) $this->barHighs[$i],
            self::BAR_L => (float) $this->barLows[$i],
            self::BAR_C => (float) $this->barCloses[$i],
            self::BAR_V => (float) $this->barVolumes[$i],
        ];
    }

    /**
     * Get execution bar data at a specific index.
     */
    private function getExecBarByIndex(int $index): array
    {
        return [
            self::BAR_T => (int) $this->execTimestamps[$index],
            self::BAR_O => (float) $this->execOpens[$index],
            self::BAR_H => (float) $this->execHighs[$index],
            self::BAR_L => (float) $this->execLows[$index],
            self::BAR_C => (float) $this->execCloses[$index],
            self::BAR_V => (float) $this->execVolumes[$index],
        ];
    }

    /**
     * Process pending orders against current bar.
     */
    private function processPendingOrders(array $currentBar): void
    {
        $pendingOrders = $this->orderManager->getPendingOrders();

        foreach ($pendingOrders as $order) {
            $executed = $this->tryExecuteOrder($order, $currentBar);

            if ($executed) {
                $this->orderManager->removePendingOrder($order->id);
            }
        }
    }

    /**
     * Try to execute a pending order.
     */
    private function tryExecuteOrder(PendingOrder $order, array $bar): bool
    {
        $canExecute = match ($order->type) {
            OrderTypeEnum::Market => true,
            OrderTypeEnum::Limit => $this->canExecuteLimit($order, $bar),
            OrderTypeEnum::Stop => $this->canExecuteStop($order, $bar),
            OrderTypeEnum::Stop_LIMIT => $this->canExecuteStopLimit($order, $bar),
        };

        if ($canExecute) {
            $executionPrice = $this->getExecutionPrice($order, $bar);
            $this->executeOrder($order, $executionPrice, $bar[self::BAR_T]);

            return true;
        }

        return false;
    }

    /**
     * Check if a limit order can be executed.
     */
    private function canExecuteLimit(PendingOrder $order, array $bar): bool
    {
        if ($order->direction === DirectionEnum::LONG) {
            return (float) $bar[self::BAR_L] <= (float) $order->price;
        }

        return (float) $bar[self::BAR_H] >= (float) $order->price;
    }

    /**
     * Check if a stop order can be executed.
     */
    private function canExecuteStop(PendingOrder $order, array $bar): bool
    {
        if ($order->direction === DirectionEnum::LONG) {
            return (float) $bar[self::BAR_H] >= (float) $order->stopPrice;
        }

        return (float) $bar[self::BAR_L] <= (float) $order->stopPrice;
    }

    /**
     * Check if a stop-limit order can be executed.
     */
    private function canExecuteStopLimit(PendingOrder $order, array $bar): bool
    {
        // First check if stop is triggered
        if (! $this->canExecuteStop($order, $bar)) {
            return false;
        }

        // Then check if limit can be filled
        return $this->canExecuteLimit($order, $bar);
    }

    /**
     * Get the execution price for an order.
     */
    private function getExecutionPrice(PendingOrder $order, array $bar): string
    {
        return match ($order->type) {
            OrderTypeEnum::Market => $bar[self::BAR_O], // Execute at next open
            OrderTypeEnum::Limit => $order->price,
            OrderTypeEnum::Stop => $order->stopPrice,
            OrderTypeEnum::Stop_LIMIT => $order->price,
        };
    }

    /**
     * Execute an order.
     */
    private function executeOrder(PendingOrder $order, string $price, int $timestamp): void
    {
        $result = $this->portfolioManager->executeOrder(
            $order,
            $price,
            Carbon::createFromTimestamp($timestamp),
            $this->commissionConfig
        );

        if ($result->position) {
            $position = $result->position;

            if ($position->exitTime !== null) {
                if ($this->openPositionIndex->hasKey($position->id)) {
                    $oldIndex = $this->openPositionIndex->get($position->id);
                    $this->positions->remove($oldIndex);
                    $this->openPositionIndex->remove($position->id);
                    $this->rebuildOpenPositionIndex();
                }

                // Tag closes with exit reason from the order if not already set
                if ($position->exitTag === null) {
                    $exitTag = $order->exitTag ?? 'counter_signal';
                    $position = new PositionDto(
                        id: $position->id,
                        symbol: $position->symbol,
                        direction: $position->direction,
                        quantity: $position->quantity,
                        entryPrice: $position->entryPrice,
                        entryTime: $position->entryTime,
                        realizedPnl: $position->realizedPnl,
                        exitPrice: $position->exitPrice,
                        exitTime: $position->exitTime,
                        stopLoss: $position->stopLoss,
                        takeProfit: $position->takeProfit,
                        costBasis: $position->costBasis,
                        commission: $position->commission,
                        exitTag: $exitTag,
                    );
                }
            } else {
                $this->openPositionIndex->put($position->id, $this->positions->count());
            }

            $this->positions->push($position);
            $this->currentCapital = $this->portfolioManager->getCashBalance();

            if ($position->exitTime !== null) {
                $this->positionTradeDetails[] = $this->buildTradeDetail(
                    $position,
                    $this->highWaterMarks[$position->id] ?? null,
                    $this->lowWaterMarks[$position->id] ?? null,
                    $this->barsInPositionTracker[$position->id] ?? null,
                );
            }
        }
    }

    private function rebuildOpenPositionIndex(): void
    {
        $this->openPositionIndex->clear();
        foreach ($this->positions as $index => $position) {
            if ($position->exitTime === null) {
                $this->openPositionIndex->put($position->id, $index);
            }
        }
    }

    /**
     * Check for stop loss / take profit exits on open positions.
     */
    private function checkPositionExits(array $bar): void
    {
        $openPositions = $this->portfolioManager->getOpenPositions();

        foreach ($openPositions as $position) {
            $exitRules = $this->getStrategyExitRules();
            $triggered = null;

            if ($exitRules !== null) {
                $context = $this->buildExitContext($position, $bar);
                $triggered = $exitRules->evaluate($context);
            } else {
                $triggered = $this->checkStaticSlTp($position, $bar);
            }

            if ($triggered !== null) {
                $this->closePositionFromTrigger($position, $triggered, $bar);
            }
        }
    }

    /**
     * Legacy static SL/TP check — extracted from original checkPositionExits().
     */
    private function checkStaticSlTp(PositionDto $position, array $bar): ?ExitTrigger
    {
        if ($position->stopLoss) {
            if ($position->direction === 'long' && (float) $bar[self::BAR_L] <= (float) $position->stopLoss) {
                return new ExitTrigger('stop_loss', (float) $position->stopLoss);
            }
            if ($position->direction === 'short' && (float) $bar[self::BAR_H] >= (float) $position->stopLoss) {
                return new ExitTrigger('stop_loss', (float) $position->stopLoss);
            }
        }

        if ($position->takeProfit) {
            if ($position->direction === 'long' && (float) $bar[self::BAR_H] >= (float) $position->takeProfit) {
                return new ExitTrigger('take_profit', (float) $position->takeProfit);
            }
            if ($position->direction === 'short' && (float) $bar[self::BAR_L] <= (float) $position->takeProfit) {
                return new ExitTrigger('take_profit', (float) $position->takeProfit);
            }
        }

        return null;
    }

    /**
     * Build an ExitContext for evaluating exit rules.
     */
    private function buildExitContext(PositionDto $position, array $bar): ExitContext
    {
        $positionId = $position->id;

        return new ExitContext(
            position: $position,
            barIndex: $this->cursor->currentIndex,
            open: $bar[self::BAR_O],
            high: $bar[self::BAR_H],
            low: $bar[self::BAR_L],
            close: $bar[self::BAR_C],
            volume: $bar[self::BAR_V],
            timestamp: $bar[self::BAR_T],
            barsInPosition: $this->barsInPositionTracker[$positionId] ?? 0,
            highestSinceEntry: $this->highWaterMarks[$positionId] ?? (float) $position->entryPrice,
            lowestSinceEntry: $this->lowWaterMarks[$positionId] ?? (float) $position->entryPrice,
        );
    }

    /**
     * Close a position triggered by an exit rule.
     */
    private function closePositionFromTrigger(PositionDto $position, ExitTrigger $trigger, array $bar): void
    {
        $exitPrice = (string) $trigger->exitPrice;
        $closedPosition = $this->portfolioManager->closePosition(
            $position->id,
            $exitPrice,
            Carbon::createFromTimestamp($bar[self::BAR_T]),
            $this->commissionConfig,
            $trigger->exitTag ?? $trigger->ruleId,
        );

        if ($closedPosition) {
            if ($this->openPositionIndex->hasKey($closedPosition->id)) {
                $oldIndex = $this->openPositionIndex->get($closedPosition->id);
                $this->positions->remove($oldIndex);
                $this->openPositionIndex->remove($closedPosition->id);
                $this->rebuildOpenPositionIndex();
            }

            $this->positions->push($closedPosition);
            $this->currentCapital = $this->portfolioManager->getCashBalance();

            $this->positionTradeDetails[] = $this->buildTradeDetail(
                $closedPosition,
                $this->highWaterMarks[$position->id] ?? null,
                $this->lowWaterMarks[$position->id] ?? null,
                $this->barsInPositionTracker[$position->id] ?? null,
            );

            unset(
                $this->highWaterMarks[$position->id],
                $this->lowWaterMarks[$position->id],
                $this->barsInPositionTracker[$position->id],
            );
        }
    }

    /**
     * Get the strategy's exit rule set, if any.
     */
    private function getStrategyExitRules(): ?ExitRuleSet
    {
        if (method_exists($this->strategy, 'getExitRules')) {
            return $this->strategy->getExitRules();
        }

        return null;
    }

    /**
     * Update high/low water marks and bar counters for open positions.
     */
    private function updatePositionWaterMarks(array $bar): void
    {
        foreach ($this->portfolioManager->getOpenPositions() as $position) {
            $id = $position->id;

            if (! isset($this->highWaterMarks[$id])) {
                $this->highWaterMarks[$id] = (float) $position->entryPrice;
                $this->lowWaterMarks[$id] = (float) $position->entryPrice;
                $this->barsInPositionTracker[$id] = 0;
            }

            $this->highWaterMarks[$id] = max($this->highWaterMarks[$id], $bar[self::BAR_H]);
            $this->lowWaterMarks[$id] = min($this->lowWaterMarks[$id], $bar[self::BAR_L]);
            $this->barsInPositionTracker[$id]++;
        }
    }

    /**
     * Record total equity (cash + mark-to-market open positions) at each bar.
     */
    private function recordBarEquity(array $bar, string $symbol): void
    {
        $equity = $this->portfolioManager->getTotalEquity([$symbol => (string) $bar[self::BAR_C]]);
        $this->barEquityCurve->push($equity);
    }

    /**
     * Call the strategy's initialize() hook before the backtest loop.
     */
    private function initializeStrategy(string $symbol, OhlcvSeries $ohlcv): void
    {
        if (! method_exists($this->strategy, 'initialize')) {
            return;
        }

        $this->strategy->initialize(new InitializeData(
            ohlcv: $ohlcv,
            initialCapital: $this->initialCapital,
            multiTimeframe: $this->multiTimeframeData,
        ));
    }

    /**
     * Call the strategy to get trading signals.
     */
    private function callStrategy(string $symbol, OhlcvSeries $ohlcv): array
    {
        if (! method_exists($this->strategy, 'onBar')) {
            return [];
        }

        return $this->strategy->onBar(new BarData(
            cursor: $this->cursor,
            ohlcv: $ohlcv,
            portfolio: $this->portfolioManager,
            symbol: $symbol,
            multiTimeframe: $this->multiTimeframeData,
        )) ?? [];
    }

    /**
     * Process trading signals from the strategy.
     */
    private function processSignals(array $signals, array $currentBar, string $symbol): void
    {
        foreach ($signals as $signal) {
            if (! $signal instanceof OrderSignal) {
                continue;
            }

            $this->createOrderFromSignal($signal, $currentBar, $symbol);
        }
    }

    /**
     * Create an order from a signal.
     */
    private function createOrderFromSignal(OrderSignal $signal, array $bar, string $symbol): void
    {
        $stakeAmount = $signal->stakeAmount ?? $this->portfolioManager->getDefaultStakeAmount();

        if (! $this->canAffordTrade($signal, $stakeAmount, $bar[self::BAR_C])) {
            return;
        }

        $pendingOrder = new PendingOrder(
            id: uniqid('order_', true),
            symbol: $symbol,
            direction: $signal->direction,
            type: $signal->orderType,
            stakeAmount: $stakeAmount,
            createdAt: Carbon::createFromTimestamp($bar[self::BAR_T]),
            price: $signal->limitPrice,
            stopPrice: $signal->stopPrice,
            stopLoss: $signal->stopLoss,
            takeProfit: $signal->takeProfit,
            exitTag: $signal->exitTags[0] ?? null,
        );

        $this->orderManager->addPendingOrder($pendingOrder);
    }

    /**
     * Check if we can afford a trade.
     */
    private function canAffordTrade(OrderSignal $signal, string $stakeAmount, string $price): bool
    {
        $cashBalance = $this->portfolioManager->getCashBalance();

        if ($signal->direction === DirectionEnum::LONG) {
            return (float) $cashBalance >= (float) $stakeAmount;
        }

        return true;
    }

    /**
     * Close all remaining positions.
     */
    private function closeAllPositions(OhlcvSeries $ohlcv): void
    {
        $openPositions = $this->portfolioManager->getOpenPositions();
        $lastIndex = $ohlcv->getTimestamps()->count() - 1;
        $lastPrice = $ohlcv->getCloses()->getVector()->get($lastIndex);
        $lastTimestamp = $ohlcv->getTimestamps()->getVector()->get($lastIndex);

        foreach ($openPositions as $position) {
            $closedPosition = $this->portfolioManager->closePosition(
                $position->id,
                $lastPrice,
                Carbon::createFromTimestamp($lastTimestamp),
                $this->commissionConfig,
                'end_of_backtest',
            );

            if ($closedPosition) {
                if ($this->openPositionIndex->hasKey($closedPosition->id)) {
                    $oldIndex = $this->openPositionIndex->get($closedPosition->id);
                    $this->positions->remove($oldIndex);
                    $this->openPositionIndex->remove($closedPosition->id);
                    $this->rebuildOpenPositionIndex();
                }
                $this->positions->push($closedPosition);

                unset(
                    $this->highWaterMarks[$position->id],
                    $this->lowWaterMarks[$position->id],
                    $this->barsInPositionTracker[$position->id],
                );
            }
        }

        $this->currentCapital = $this->portfolioManager->getCashBalance();
    }

    /**
     * Build a trade detail record including MAE/MFE.
     *
     * @return array<string, mixed>
     */
    private function buildTradeDetail(
        PositionDto $position,
        ?float $highWater,
        ?float $lowWater,
        ?int $barsHeld,
    ): array {
        $entryPrice = (float) $position->entryPrice;
        $isLong = $position->direction === 'long';

        $mae = null;
        $mfe = null;

        if ($highWater !== null && $lowWater !== null) {
            if ($isLong) {
                $mfe = $highWater - $entryPrice;
                $mae = $entryPrice - $lowWater;
            } else {
                $mfe = $entryPrice - $lowWater;
                $mae = $highWater - $entryPrice;
            }
        }

        return [
            'direction' => $position->direction,
            'entry_price' => $entryPrice,
            'exit_price' => (float) ($position->exitPrice ?? 0),
            'pnl' => (float) $position->realizedPnl,
            'entry_time' => $position->entryTime->toIso8601String(),
            'exit_time' => $position->exitTime?->toIso8601String(),
            'bars_held' => $barsHeld,
            'exit_reason' => $position->exitTag,
            'mae' => $mae,
            'mfe' => $mfe,
            'quantity' => (float) $position->quantity,
        ];
    }

    /**
     * Compute bars per year from the signal timeframe.
     */
    private function computeBarsPerYear(): int
    {
        if ($this->signalTimeframe === null) {
            return 252;
        }

        $secondsPerYear = 31536000;

        return max(1, (int) round($secondsPerYear / $this->signalTimeframe->toSeconds()));
    }

    /**
     * Extract realized P&L values from all closed positions.
     *
     * @return array<int, string>
     */
    private function extractClosedPositionPnl(): array
    {
        $pnlValues = [];

        foreach ($this->positions as $position) {
            if ($position->exitTime !== null) {
                $pnlValues[] = $position->realizedPnl;
            }
        }

        return $pnlValues;
    }
}
