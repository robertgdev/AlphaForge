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

#[AsStrategy(
    alias: 'rsi_reversal',
    name: 'RSI Reversal',
    description: 'RSI overbought/oversold reversal strategy. Buys when RSI recovers from oversold, sells when RSI retreats from overbought.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class RsiReversalStrategy implements StrategyInterface
{
    use DefaultExitRules;

    #[Input(
        description: 'RSI lookback period',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $rsiPeriod = 14;

    #[Input(
        description: 'RSI oversold threshold for buy signal',
        min: 10,
        max: 40,
        step: 5
    )]
    private float $oversoldThreshold = 30.0;

    #[Input(
        description: 'RSI overbought threshold for sell signal',
        min: 60,
        max: 90,
        step: 5
    )]
    private float $overboughtThreshold = 70.0;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 3.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 6.0;

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

    private int $totalBars = 0;

    public function configure(array $inputs): void
    {
        if (isset($inputs['rsiPeriod'])) {
            $this->rsiPeriod = (int) $inputs['rsiPeriod'];
        }
        if (isset($inputs['oversoldThreshold'])) {
            $this->oversoldThreshold = (float) $inputs['oversoldThreshold'];
        }
        if (isset($inputs['overboughtThreshold'])) {
            $this->overboughtThreshold = (float) $inputs['overboughtThreshold'];
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

        $totalBars = $ohlcv->getTimestamps()->count();

        if ($totalBars < $this->rsiPeriod) {
            throw new \RuntimeException(
                sprintf(
                    'Insufficient data for RSI Reversal strategy. Need at least %d bars, got %d.',
                    $this->rsiPeriod,
                    $totalBars
                )
            );
        }

        $rsi = $this->ctx->rsi($this->rsiPeriod);

        $this->entryCondition = $rsi->isBelow($this->oversoldThreshold)->and($rsi->isRising());
        $this->exitCondition = $rsi->isAbove($this->overboughtThreshold)->and($rsi->isFalling());

        $this->totalBars = $totalBars;
        $this->entrySignals = $this->entryCondition->evaluateAll($this->totalBars);
        $this->exitSignals = $this->exitCondition->evaluateAll($this->totalBars);
        $this->closePrices = $ohlcv->getCloses()->getVector()->toArray();
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
            $stopLoss = bcmul($currentPrice, bcdiv((string) (100 - $this->stopLossPercent), '100', 6), 6);
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
