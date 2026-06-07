<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Condition\ConditionInterface;
use App\AlphaForge\ExitRule\DefaultExitRules;
use App\AlphaForge\Indicator\Model\IndicatorContext;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\StrategyInterface;

abstract class BaseSignalStrategy implements StrategyInterface
{
    use DefaultExitRules;

    protected float $positionSizePercent = 1.0;

    protected float $initialCapital = 10000.0;

    protected ?IndicatorContext $ctx = null;

    protected ?ConditionInterface $entryCondition = null;

    protected ?ConditionInterface $exitCondition = null;

    /** @var array<int, bool> */
    protected array $entrySignals = [];

    /** @var array<int, bool> */
    protected array $exitSignals = [];

    /** @var array<int, float> */
    protected array $closePrices = [];

    protected int $totalBars = 0;

    public function configure(array $inputs): void
    {
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            $inputAttrs = $property->getAttributes(Input::class);

            if (empty($inputAttrs)) {
                continue;
            }

            $propName = $property->getName();

            if (! array_key_exists($propName, $inputs)) {
                continue;
            }

            $value = $inputs[$propName];
            $propertyType = $property->getType();

            if ($propertyType instanceof \ReflectionNamedType) {
                $value = match ($propertyType->getName()) {
                    'int' => (int) $value,
                    'float' => (float) $value,
                    'bool' => (bool) $value,
                    'string' => (string) $value,
                    'array' => (array) $value,
                    default => $value,
                };
            }

            $property->setAccessible(true);
            $property->setValue($this, $value);
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

        $this->totalBars = $ohlcv->getTimestamps()->count();

        $minBars = $this->minBars();

        if ($this->totalBars < $minBars) {
            throw new \RuntimeException(
                sprintf(
                    'Insufficient data for %s strategy. Need at least %d bars, got %d.',
                    $this->strategyName(),
                    $minBars,
                    $this->totalBars
                )
            );
        }

        $this->computeSignals($ohlcv);

        $this->entrySignals = $this->entryCondition->evaluateAll($this->totalBars);
        $this->exitSignals = $this->exitCondition->evaluateAll($this->totalBars);
        $this->closePrices = $ohlcv->getCloses()->getVector()->toArray();
    }

    final public function onBar(array $data): array
    {
        $signals = [];
        $currentIndex = $data['cursor']->currentIndex;
        $portfolio = $data['portfolio'];
        $symbol = $data['symbol'];

        $currentPrice = (string) $this->closePrices[$currentIndex];
        $openPosition = $portfolio->getOpenPosition($symbol);

        if (($this->entrySignals[$currentIndex] ?? false) && $openPosition === null) {
            $stopLoss = $this->calculateStopLoss($currentPrice, $currentIndex);
            $takeProfit = $this->calculateTakeProfit($currentPrice, $currentIndex);

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

    protected function calculateStopLoss(string $currentPrice, int $currentIndex): string
    {
        return bcmul($currentPrice, bcdiv((string) (100 - $this->stopLossPercent()), '100', 6), 6);
    }

    protected function calculateTakeProfit(string $currentPrice, int $currentIndex): string
    {
        return bcmul($currentPrice, bcdiv((string) (100 + $this->takeProfitPercent()), '100', 6), 6);
    }

    /**
     * Compute indicators and set $entryCondition / $exitCondition.
     *
     * Called from initialize() after ctx is created and minBars is validated.
     * Subclasses compute their specific indicators and assign to
     * $this->entryCondition and $this->exitCondition.
     */
    abstract protected function computeSignals(OhlcvSeries $ohlcv): void;

    /**
     * Minimum number of bars required for this strategy.
     */
    abstract protected function minBars(): int;

    /**
     * Human-readable strategy name for error messages.
     */
    abstract protected function strategyName(): string;

    /**
     * Current stop loss percentage from the #[Input] property.
     */
    abstract protected function stopLossPercent(): float;

    /**
     * Current take profit percentage from the #[Input] property.
     */
    abstract protected function takeProfitPercent(): float;
}
