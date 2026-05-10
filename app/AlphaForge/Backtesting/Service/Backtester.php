<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Dto\PendingOrder;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Order\Model\OrderManager;
use App\AlphaForge\Order\Model\PortfolioManager;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;
use RuntimeException;

class Backtester
{
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
        ?TimeframeEnum $executionTimeframe = null
    ): array {
        // Validate execution timeframe is lower than signal timeframe
        if ($executionTimeframe !== null && $executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
            throw new RuntimeException(
                "Execution timeframe ({$executionTimeframe->value}) must be lower (finer) than the signal timeframe ({$timeframe->value})."
            );
        }

        // Initialize
        $this->initialize($initialCapital, $commissionConfig);

        // Load strategy
        $this->strategy = $this->strategyRegistry->get($strategyAlias);
        $this->configureStrategy($strategyInputs);

        // Store timeframe references
        $this->signalTimeframe = $timeframe;
        $this->executionTimeframe = $executionTimeframe;

        // Load market data
        $this->loadMarketData($symbols, $timeframe, $exchange, $additionalTimeframes, $startDate, $endDate, $executionTimeframe);

        // Run the backtest loop
        $this->runBacktestLoop($symbols);

        // Calculate statistics
        $statistics = $this->statisticsService->calculate(
            $this->positions,
            $this->initialCapital,
            $this->currentCapital
        );

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
        ?TimeframeEnum $executionTimeframe
    ): void {
        // Load signal timeframe data
        foreach ($symbols as $symbol) {
            $filePath = $this->getMarketDataPath($symbol, $timeframe, $exchange);
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
                $filePath = $this->getMarketDataPath($symbol, $executionTimeframe, $exchange);

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
        $records = iterator_to_array($this->binaryStorage->readRecordsSequentially($filePath));

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
    private function getMarketDataPath(string $symbol, TimeframeEnum $timeframe, string $exchange): string
    {
        return sprintf(
            '%s/%s/%s/%s/ohlcv.stchx',
            $this->marketDataPath,
            $exchange,
            strtoupper($symbol),
            $timeframe->value
        );
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
                if ($timestamps[$i] >= $startDate->timestamp) {
                    $startIndex = $i;
                    break;
                }
            }
        }

        if ($endDate) {
            for ($i = $timestamps->count() - 1; $i >= 0; $i--) {
                if ($timestamps[$i] <= $endDate->timestamp) {
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

        $this->initializeStrategy($primarySymbol, $primaryOhlcv);

        for ($this->cursor->currentIndex = 0; $this->cursor->currentIndex < $totalBars; $this->cursor->currentIndex++) {
            // Get current bar data
            $currentBar = $this->getCurrentBar($primaryOhlcv);

            // Process pending orders
            $this->processPendingOrders($currentBar);

            // Check for stop loss / take profit on open positions
            $this->checkPositionExits($currentBar);

            // Call strategy for signals
            $signals = $this->callStrategy($primarySymbol, $primaryOhlcv);

            // Process signals
            $this->processSignals($signals, $currentBar, $primarySymbol);
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
                $execBar = $this->getBarByIndex($executionOhlcv, $execIndex);

                // Process pending orders at execution granularity
                $this->processPendingOrders($execBar);

                // Check SL/TP at execution granularity
                $this->checkPositionExits($execBar);

                $execIndex++;
            }

            // Call strategy on signal timeframe bar to generate new signals
            $signals = $this->callStrategy($symbol, $signalOhlcv);

            // Process signals into pending orders (will be evaluated on next iteration's M1 bars)
            $signalBar = $this->getCurrentBar($signalOhlcv);
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
            $execBar = $this->getBarByIndex($executionOhlcv, $execIndex);

            $this->processPendingOrders($execBar);
            $this->checkPositionExits($execBar);

            $execIndex++;
        }
    }

    /**
     * Get the current bar data from the signal timeframe.
     *
     * @return array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}
     */
    private function getCurrentBar(OhlcvSeries $ohlcv): array
    {
        $i = $this->cursor->currentIndex;

        return [
            'timestamp' => (int) $ohlcv->getTimestamps()->getVector()->get($i),
            'open' => (float) $ohlcv->getOpens()->getVector()->get($i),
            'high' => (float) $ohlcv->getHighs()->getVector()->get($i),
            'low' => (float) $ohlcv->getLows()->getVector()->get($i),
            'close' => (float) $ohlcv->getCloses()->getVector()->get($i),
            'volume' => (float) $ohlcv->getVolumes()->getVector()->get($i),
        ];
    }

    /**
     * Get bar data at a specific index from any OhlcvSeries.
     *
     * @return array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}
     */
    private function getBarByIndex(OhlcvSeries $ohlcv, int $index): array
    {
        return [
            'timestamp' => (int) $ohlcv->getTimestamps()->getVector()->get($index),
            'open' => (float) $ohlcv->getOpens()->getVector()->get($index),
            'high' => (float) $ohlcv->getHighs()->getVector()->get($index),
            'low' => (float) $ohlcv->getLows()->getVector()->get($index),
            'close' => (float) $ohlcv->getCloses()->getVector()->get($index),
            'volume' => (float) $ohlcv->getVolumes()->getVector()->get($index),
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
            $this->executeOrder($order, $executionPrice, $bar['timestamp']);

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
            return bccomp($bar['low'], $order->price, 12) <= 0;
        }

        return bccomp($bar['high'], $order->price, 12) >= 0;
    }

    /**
     * Check if a stop order can be executed.
     */
    private function canExecuteStop(PendingOrder $order, array $bar): bool
    {
        if ($order->direction === DirectionEnum::LONG) {
            return bccomp($bar['high'], $order->stopPrice, 12) >= 0;
        }

        return bccomp($bar['low'], $order->stopPrice, 12) <= 0;
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
            OrderTypeEnum::Market => $bar['open'], // Execute at next open
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
            } else {
                $this->openPositionIndex->put($position->id, $this->positions->count());
            }

            $this->positions->push($position);
            $this->currentCapital = $this->portfolioManager->getCashBalance();
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
            $shouldClose = false;
            $closePrice = $bar['close'];

            // Check stop loss
            if ($position->stopLoss) {
                if ($position->direction === 'long' && bccomp($bar['low'], $position->stopLoss, 12) <= 0) {
                    $shouldClose = true;
                    $closePrice = $position->stopLoss;
                } elseif ($position->direction === 'short' && bccomp($bar['high'], $position->stopLoss, 12) >= 0) {
                    $shouldClose = true;
                    $closePrice = $position->stopLoss;
                }
            }

            // Check take profit
            if (! $shouldClose && $position->takeProfit) {
                if ($position->direction === 'long' && bccomp($bar['high'], $position->takeProfit, 12) >= 0) {
                    $shouldClose = true;
                    $closePrice = $position->takeProfit;
                } elseif ($position->direction === 'short' && bccomp($bar['low'], $position->takeProfit, 12) <= 0) {
                    $shouldClose = true;
                    $closePrice = $position->takeProfit;
                }
            }

            if ($shouldClose) {
                $closedPosition = $this->portfolioManager->closePosition(
                    $position->id,
                    $closePrice,
                    Carbon::createFromTimestamp($bar['timestamp']),
                    $this->commissionConfig
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
                }
            }
        }
    }

    /**
     * Call the strategy's initialize() hook before the backtest loop.
     */
    private function initializeStrategy(string $symbol, OhlcvSeries $ohlcv): void
    {
        if (! method_exists($this->strategy, 'initialize')) {
            return;
        }

        $data = [
            'symbol' => $symbol,
            'ohlcv' => $ohlcv,
            'multi_timeframe' => $this->multiTimeframeData,
        ];

        $this->strategy->initialize($data);
    }

    /**
     * Call the strategy to get trading signals.
     */
    private function callStrategy(string $symbol, OhlcvSeries $ohlcv): array
    {
        if (! method_exists($this->strategy, 'onBar')) {
            return [];
        }

        // Prepare data for strategy
        $data = [
            'symbol' => $symbol,
            'ohlcv' => $ohlcv,
            'cursor' => $this->cursor,
            'portfolio' => $this->portfolioManager,
            'multi_timeframe' => $this->multiTimeframeData,
        ];

        return $this->strategy->onBar($data) ?? [];
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
        // Check if we have enough capital
        $stakeAmount = $signal->stakeAmount ?? $this->portfolioManager->getDefaultStakeAmount();

        if (! $this->canAffordTrade($signal, $stakeAmount, $bar['close'])) {
            return;
        }

        $pendingOrder = new PendingOrder(
            id: uniqid('order_', true),
            symbol: $symbol,
            direction: $signal->direction,
            type: $signal->orderType,
            stakeAmount: $stakeAmount,
            price: $signal->limitPrice,
            stopPrice: $signal->stopPrice,
            stopLoss: $signal->stopLoss,
            takeProfit: $signal->takeProfit,
            createdAt: Carbon::createFromTimestamp($bar['timestamp']),
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
            return bccomp($cashBalance, $stakeAmount, 12) >= 0;
        }

        // For short positions, check if we have the position to close or can short
        return true; // Simplified - would need margin calculation
    }

    /**
     * Close all remaining positions.
     */
    private function closeAllPositions(OhlcvSeries $ohlcv): void
    {
        $openPositions = $this->portfolioManager->getOpenPositions();
        $lastIndex = $ohlcv->getTimestamps()->count() - 1;
        $lastPrice = $ohlcv->getCloses()[$lastIndex];
        $lastTimestamp = $ohlcv->getTimestamps()[$lastIndex];

        foreach ($openPositions as $position) {
            $closedPosition = $this->portfolioManager->closePosition(
                $position->id,
                $lastPrice,
                Carbon::createFromTimestamp($lastTimestamp),
                $this->commissionConfig
            );

            if ($closedPosition) {
                if ($this->openPositionIndex->hasKey($closedPosition->id)) {
                    $oldIndex = $this->openPositionIndex->get($closedPosition->id);
                    $this->positions->remove($oldIndex);
                    $this->openPositionIndex->remove($closedPosition->id);
                    $this->rebuildOpenPositionIndex();
                }
                $this->positions->push($closedPosition);
            }
        }

        $this->currentCapital = $this->portfolioManager->getCashBalance();
    }
}
