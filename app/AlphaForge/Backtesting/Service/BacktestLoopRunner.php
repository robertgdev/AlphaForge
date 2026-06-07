<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
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
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;

/**
 * Runs the bar-by-bar backtest loop — signal generation, order execution,
 * position management, and exit rule evaluation.
 *
 * Extracted from Backtester to keep the orchestrator focused on setup,
 * statistics, and result assembly.
 */
class BacktestLoopRunner
{
    public const BAR_T = 0;

    public const BAR_O = 1;

    public const BAR_H = 2;

    public const BAR_L = 3;

    public const BAR_C = 4;

    public const BAR_V = 5;

    // ── writable state (read back by Backtester after the loop) ──

    /** @var Vector<mixed> All positions */
    public Vector $positions;

    /** @var Vector<string> Bar-level equity curve */
    public Vector $barEquityCurve;

    /** @var array<int, array<string, mixed>> */
    public array $positionTradeDetails = [];

    public string $currentCapital;

    /** @var array<string, float> */
    public array $highWaterMarks = [];

    /** @var array<string, float> */
    public array $lowWaterMarks = [];

    /** @var array<string, int> */
    public array $barsInPositionTracker = [];

    // ── internal mutable state ──

    private BacktestCursor $cursor;

    private OrderManager $orderManager;

    private PortfolioManager $portfolioManager;

    /** @var Map<string, int> */
    private Map $openPositionIndex;

    private ?MultiTimeframeOhlcvSeries $multiTimeframeData = null;

    // ── pre-extracted bar arrays (immutable during loop) ──

    /** @var array<int> */
    private array $barTimestamps;

    /** @var array<float> */
    private array $barOpens;

    /** @var array<float> */
    private array $barHighs;

    /** @var array<float> */
    private array $barLows;

    /** @var array<float> */
    private array $barCloses;

    /** @var array<float> */
    private array $barVolumes;

    /** @var array<int>|null */
    private ?array $execTimestamps = null;

    /** @var array<float>|null */
    private ?array $execOpens = null;

    /** @var array<float>|null */
    private ?array $execHighs = null;

    /** @var array<float>|null */
    private ?array $execLows = null;

    /** @var array<float>|null */
    private ?array $execCloses = null;

    /** @var array<float>|null */
    private ?array $execVolumes = null;

    /** @var Map<string, OhlcvSeries> */
    private Map $ohlcvData;

    /** @var Map<string, OhlcvSeries>|null */
    private ?Map $executionOhlcvData = null;

    // ── configuration ──

    private object $strategy;

    private string $initialCapital;

    /** @var array<string, string> */
    private array $commissionConfig;

    private ?TimeframeEnum $signalTimeframe = null;

    private ?TimeframeEnum $executionTimeframe = null;

    /** @var callable|null */
    private $progressCallback = null;

    /**
     * @param  array<int>  $barTimestamps
     * @param  array<float>  $barOpens
     * @param  array<float>  $barHighs
     * @param  array<float>  $barLows
     * @param  array<float>  $barCloses
     * @param  array<float>  $barVolumes
     * @param  array<int>|null  $execTimestamps
     * @param  array<float>|null  $execOpens
     * @param  array<float>|null  $execHighs
     * @param  array<float>|null  $execLows
     * @param  array<float>|null  $execCloses
     * @param  array<float>|null  $execVolumes
     * @param  Map<string, OhlcvSeries>  $ohlcvData
     * @param  Map<string, OhlcvSeries>|null  $executionOhlcvData
     */
    public function __construct(
        string $initialCapital,
        array $commissionConfig,
        array $barTimestamps,
        array $barOpens,
        array $barHighs,
        array $barLows,
        array $barCloses,
        array $barVolumes,
        ?array $execTimestamps,
        ?array $execOpens,
        ?array $execHighs,
        ?array $execLows,
        ?array $execCloses,
        ?array $execVolumes,
        Map $ohlcvData,
        ?Map $executionOhlcvData,
        object $strategy,
        ?TimeframeEnum $signalTimeframe,
        ?TimeframeEnum $executionTimeframe,
        ?MultiTimeframeOhlcvSeries $multiTimeframeData,
        ?callable $progressCallback = null,
    ) {
        $this->initialCapital = $initialCapital;
        $this->commissionConfig = $commissionConfig;
        $this->strategy = $strategy;
        $this->signalTimeframe = $signalTimeframe;
        $this->executionTimeframe = $executionTimeframe;
        $this->multiTimeframeData = $multiTimeframeData;
        $this->progressCallback = $progressCallback;

        // Bar arrays
        $this->barTimestamps = $barTimestamps;
        $this->barOpens = $barOpens;
        $this->barHighs = $barHighs;
        $this->barLows = $barLows;
        $this->barCloses = $barCloses;
        $this->barVolumes = $barVolumes;

        // Execution bar arrays
        $this->execTimestamps = $execTimestamps;
        $this->execOpens = $execOpens;
        $this->execHighs = $execHighs;
        $this->execLows = $execLows;
        $this->execCloses = $execCloses;
        $this->execVolumes = $execVolumes;

        // Data maps
        $this->ohlcvData = $ohlcvData;
        $this->executionOhlcvData = $executionOhlcvData;

        // Fresh mutable state for this run
        $this->cursor = new BacktestCursor;
        $this->orderManager = new OrderManager;
        $this->portfolioManager = new PortfolioManager($initialCapital);
        $this->positions = new Vector;
        $this->openPositionIndex = new Map;
        $this->currentCapital = $initialCapital;
        $this->barEquityCurve = new Vector;
    }

    /**
     * Run the backtest loop for the given symbols.
     *
     * After this returns, read results from the public properties:
     * $positions, $currentCapital, $barEquityCurve, $positionTradeDetails,
     * $highWaterMarks, $lowWaterMarks, $barsInPositionTracker.
     */
    public function run(array $symbols): void
    {
        $primarySymbol = $symbols[0];
        $primaryOhlcv = $this->ohlcvData->get($primarySymbol);

        if ($this->executionTimeframe !== null && $this->executionOhlcvData !== null) {
            $this->runDualTimeframeLoop($primarySymbol, $primaryOhlcv);
        } else {
            $this->runSingleTimeframeLoop($primarySymbol, $primaryOhlcv);
        }

        $this->closeAllPositions($primaryOhlcv);
    }

    // ──────────────────────────────────────────────
    //  Loop: single-timeframe
    // ──────────────────────────────────────────────

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

            $currentBar = $this->getCurrentBar();

            if ($this->orderManager->hasPendingOrders()) {
                $this->processPendingOrders($currentBar);
            }

            $hasPositions = $this->portfolioManager->hasOpenPositions();

            if ($hasPositions) {
                $this->checkPositionExits($currentBar);
                $this->updatePositionWaterMarks($currentBar);
            }

            $signals = $this->callStrategy($primarySymbol, $primaryOhlcv);

            $this->processSignals($signals, $currentBar, $primarySymbol);

            $this->recordBarEquity($currentBar, $primarySymbol);
        }
    }

    // ──────────────────────────────────────────────
    //  Loop: dual-timeframe
    // ──────────────────────────────────────────────

    private function runDualTimeframeLoop(string $symbol, OhlcvSeries $signalOhlcv): void
    {
        $executionOhlcv = $this->executionOhlcvData->get($symbol);
        $signalTimestamps = $signalOhlcv->getTimestamps();
        $execTimestamps = $executionOhlcv->getTimestamps();

        $totalSignalBars = $signalTimestamps->count();
        $totalExecBars = $execTimestamps->count();
        $signalBarDuration = $this->signalTimeframe->toSeconds();

        // Find the first execution bar that corresponds to the start of signal data
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

            $signalBarStart = $signalTimestamps->getVector()->get($signalIndex);
            $signalBarEnd = $signalBarStart + $signalBarDuration;

            while ($execIndex < $totalExecBars) {
                $execBarTimestamp = $execTimestamps->getVector()->get($execIndex);

                if ($execBarTimestamp >= $signalBarEnd) {
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

            $signals = $this->callStrategy($symbol, $signalOhlcv);

            $signalBar = $this->getCurrentBar();
            $this->processSignals($signals, $signalBar, $symbol);
        }

        // Process remaining execution bars up to end of last signal bar
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

    // ──────────────────────────────────────────────
    //  Bar accessors
    // ──────────────────────────────────────────────

    /** @return array{int|float, float, float, float, float, float} */
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

    /** @return array{int, float, float, float, float, float} */
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

    // ──────────────────────────────────────────────
    //  Strategy interaction
    // ──────────────────────────────────────────────

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

    private function processSignals(array $signals, array $currentBar, string $symbol): void
    {
        foreach ($signals as $signal) {
            if (! $signal instanceof OrderSignal) {
                continue;
            }

            $this->createOrderFromSignal($signal, $currentBar, $symbol);
        }
    }

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

    private function canAffordTrade(OrderSignal $signal, string $stakeAmount, string $price): bool
    {
        $cashBalance = $this->portfolioManager->getCashBalance();

        if ($signal->direction === DirectionEnum::LONG) {
            return (float) $cashBalance >= (float) $stakeAmount;
        }

        return true;
    }

    // ──────────────────────────────────────────────
    //  Order execution
    // ──────────────────────────────────────────────

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

    private function canExecuteLimit(PendingOrder $order, array $bar): bool
    {
        if ($order->direction === DirectionEnum::LONG) {
            return (float) $bar[self::BAR_L] <= (float) $order->price;
        }

        return (float) $bar[self::BAR_H] >= (float) $order->price;
    }

    private function canExecuteStop(PendingOrder $order, array $bar): bool
    {
        if ($order->direction === DirectionEnum::LONG) {
            return (float) $bar[self::BAR_H] >= (float) $order->stopPrice;
        }

        return (float) $bar[self::BAR_L] <= (float) $order->stopPrice;
    }

    private function canExecuteStopLimit(PendingOrder $order, array $bar): bool
    {
        if (! $this->canExecuteStop($order, $bar)) {
            return false;
        }

        return $this->canExecuteLimit($order, $bar);
    }

    private function getExecutionPrice(PendingOrder $order, array $bar): string
    {
        return match ($order->type) {
            OrderTypeEnum::Market => $bar[self::BAR_O],
            OrderTypeEnum::Limit => $order->price,
            OrderTypeEnum::Stop => $order->stopPrice,
            OrderTypeEnum::Stop_LIMIT => $order->price,
        };
    }

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

    // ──────────────────────────────────────────────
    //  Position exits (SL / TP / custom rules)
    // ──────────────────────────────────────────────

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

    private function getStrategyExitRules(): ?ExitRuleSet
    {
        if (method_exists($this->strategy, 'getExitRules')) {
            return $this->strategy->getExitRules();
        }

        return null;
    }

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

    // ──────────────────────────────────────────────
    //  Position tracking
    // ──────────────────────────────────────────────

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

    private function recordBarEquity(array $bar, string $symbol): void
    {
        $equity = $this->portfolioManager->getTotalEquity([$symbol => (string) $bar[self::BAR_C]]);
        $this->barEquityCurve->push($equity);
    }

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

    // ──────────────────────────────────────────────
    //  Post-processing helpers
    // ──────────────────────────────────────────────

    /**
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

    // ──────────────────────────────────────────────
    //  Progress
    // ──────────────────────────────────────────────

    private function emitProgress(int $current, int $total, string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($current, $total, $message);
        }
    }
}
