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
    alias: 'stoch_reversal',
    name: 'Stochastic Reversal',
    description: 'Stochastic oscillator crossover strategy. Buys when %K crosses above %D, sells when %K crosses below %D.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class StochasticStrategy implements StrategyInterface
{
    use DefaultExitRules;

    #[Input(
        description: 'Stochastic %K period (fast)',
        min: 5,
        max: 30,
        step: 1
    )]
    private int $fastKPeriod = 14;

    #[Input(
        description: 'Stochastic %K smoothing period',
        min: 1,
        max: 10,
        step: 1
    )]
    private int $slowKPeriod = 3;

    #[Input(
        description: 'Stochastic %D smoothing period',
        min: 1,
        max: 10,
        step: 1
    )]
    private int $slowDPeriod = 3;

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
        if (isset($inputs['fastKPeriod'])) {
            $this->fastKPeriod = (int) $inputs['fastKPeriod'];
        }
        if (isset($inputs['slowKPeriod'])) {
            $this->slowKPeriod = (int) $inputs['slowKPeriod'];
        }
        if (isset($inputs['slowDPeriod'])) {
            $this->slowDPeriod = (int) $inputs['slowDPeriod'];
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

        $minBars = $this->fastKPeriod + $this->slowKPeriod + $this->slowDPeriod;
        $totalBars = $ohlcv->getTimestamps()->count();

        if ($totalBars < $minBars) {
            throw new \RuntimeException(
                sprintf(
                    'Insufficient data for Stochastic Reversal strategy. Need at least %d bars, got %d.',
                    $minBars,
                    $totalBars
                )
            );
        }

        $stoch = $this->ctx->stoch(
            $this->fastKPeriod,
            $this->slowKPeriod,
            0, // slowKMaType: SMA
            $this->slowDPeriod,
            0  // slowDMaType: SMA
        );

        $k = $stoch->get('slowK');
        $d = $stoch->get('slowD');

        $this->entryCondition = $k->crossesAbove($d);
        $this->exitCondition = $k->crossesBelow($d);

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
