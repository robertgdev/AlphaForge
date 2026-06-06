<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Condition\ConditionInterface;
use App\AlphaForge\ExitRule\DefaultExitRules;
use App\AlphaForge\Indicator\Model\IndicatorContext;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\StrategyInterface;
use App\AlphaForge\TimeSeries\TimeSeriesInterface;

#[AsStrategy(
    alias: 'breakout',
    name: 'Volatility Breakout',
    description: 'Breakout strategy that enters when price breaks above the highest high of the lookback period. Uses ATR-based exits and stop management.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class BreakoutStrategy implements StrategyInterface
{
    use DefaultExitRules;

    #[Input(
        description: 'Lookback period for highest-high breakout',
        min: 10,
        max: 60,
        step: 5
    )]
    private int $lookback = 20;

    #[Input(
        description: 'ATR period for trailing stop',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $atrPeriod = 14;

    #[Input(
        description: 'ATR multiplier for trailing stop distance',
        min: 1.0,
        max: 5.0,
        step: 0.5
    )]
    private float $atrMultiplier = 2.0;

    #[Input(
        description: 'Stop loss percentage from entry (fallback when ATR unavailable)',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 4.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 12.0;

    private float $positionSizePercent = 1.0;

    private float $initialCapital = 10000.0;

    private ?IndicatorContext $ctx = null;

    private ?ConditionInterface $entryCondition = null;

    private ?ConditionInterface $exitCondition = null;

    /** @var array<int, bool> */
    private array $entrySignals = [];

    /** @var array<int, bool> */
    private array $exitSignals = [];

    /** @var array<int, float> */
    private array $closePrices = [];

    /** @var array<int, float> */
    private array $atrValues = [];

    /** @var array<int, float> */
    private array $highPrices = [];

    private int $totalBars = 0;

    private ?TimeSeriesInterface $atr = null;

    public function configure(array $inputs): void
    {
        if (isset($inputs['lookback'])) {
            $this->lookback = (int) $inputs['lookback'];
        }
        if (isset($inputs['atrPeriod'])) {
            $this->atrPeriod = (int) $inputs['atrPeriod'];
        }
        if (isset($inputs['atrMultiplier'])) {
            $this->atrMultiplier = (float) $inputs['atrMultiplier'];
        }
        if (isset($inputs['stopLossPercent'])) {
            $this->stopLossPercent = (float) $inputs['stopLossPercent'];
        }
        if (isset($inputs['takeProfitPercent'])) {
            $this->takeProfitPercent = (float) $inputs['takeProfitPercent'];
        }
        if (isset($inputs['positionSizePercent'])) {
            $this->positionSizePercent = (float) $inputs['positionSizePercent'];
        }
    }

    public function initialize(array $data): void
    {
        $ohlcv = $data['ohlcv'];
        $this->ctx = new IndicatorContext($ohlcv);

        $this->initialCapital = (float) ($data['initial_capital'] ?? '10000');

        $minBars = max($this->lookback, $this->atrPeriod) + 10;
        $totalBars = $ohlcv->getTimestamps()->count();

        if ($totalBars < $minBars) {
            throw new \RuntimeException(
                sprintf(
                    'Insufficient data for Breakout strategy. Need at least %d bars, got %d.',
                    $minBars,
                    $totalBars
                )
            );
        }

        $close = $this->ctx->priceSeries('close');

        // Compute rolling highest high using the 'max' indicator with high prices as input
        $highSeries = $this->ctx->priceSeries('high');
        $highestHigh = $this->ctx->indicator('max', ['period' => $this->lookback], ['close' => $highSeries]);

        // Entry: close price breaks above the highest high of the lookback
        $this->entryCondition = $close->crossesAbove($highestHigh);

        // Exit: close crosses below lowest low of the last half-lookback bars
        $lowSeries = $this->ctx->priceSeries('low');
        $halfLookback = max(5, (int) ($this->lookback / 2));
        $lowestLow = $this->ctx->indicator('min', ['period' => $halfLookback], ['close' => $lowSeries]);
        $this->exitCondition = $close->crossesBelow($lowestLow);

        // Pre-extract ATR values for dynamic SL/TP in onBar
        $this->atr = $this->ctx->atr($this->atrPeriod);
        $this->atrValues = $this->atr->toArray();

        $this->totalBars = $totalBars;
        $this->entrySignals = $this->entryCondition->evaluateAll($this->totalBars);
        $this->exitSignals = $this->exitCondition->evaluateAll($this->totalBars);
        $this->closePrices = $ohlcv->getCloses()->getVector()->toArray();
        $this->highPrices = $ohlcv->getHighs()->getVector()->toArray();
    }

    public function onBar(array $data): array
    {
        $signals = [];
        $currentIndex = $data['cursor']->currentIndex;
        $portfolio = $data['portfolio'];
        $symbol = $data['symbol'];

        $currentPrice = (string) $this->closePrices[$currentIndex];
        $openPosition = $portfolio->getOpenPosition($symbol);

        if (($this->entrySignals[$currentIndex] ?? false) && $openPosition === null) {
            // Use ATR-based stop loss when available, fall back to percentage
            $atrVal = $this->atrValues[$currentIndex] ?? null;
            if ($atrVal !== null && $atrVal > 0) {
                $stopDistance = $atrVal * $this->atrMultiplier;
                $stopLoss = bcsub($currentPrice, (string) $stopDistance, 6);
            } else {
                $stopLoss = bcmul($currentPrice, bcdiv((string) (100 - $this->stopLossPercent), '100', 6), 6);
            }

            $takeProfit = bcmul($currentPrice, bcdiv((string) (100 + $this->takeProfitPercent), '100', 6), 6);

            $signals[] = new OrderSignal(
                symbol: $symbol,
                direction: DirectionEnum::LONG,
                orderType: OrderTypeEnum::Market,
                stakeAmount: (string) ($this->initialCapital * $this->positionSizePercent / 100.0),
                stopLoss: $stopLoss,
                takeProfit: $takeProfit,
            );
        }

        if (($this->exitSignals[$currentIndex] ?? false) && $openPosition !== null) {
            $signals[] = new OrderSignal(
                symbol: $symbol,
                direction: DirectionEnum::SHORT,
                orderType: OrderTypeEnum::Market,
                quantity: (string) $openPosition->quantity,
                exitTags: ['strategy_signal'],
            );
        }

        return $signals;
    }
}
