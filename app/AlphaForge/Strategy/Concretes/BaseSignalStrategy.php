<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Condition\ConditionInterface;
use App\AlphaForge\ExitRule\DefaultExitRules;
use App\AlphaForge\Indicator\Model\IndicatorContext;
use App\AlphaForge\Order\Service\OrderCalculator;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\Dto\InitializeData;
use App\AlphaForge\Strategy\StrategyInterface;

abstract class BaseSignalStrategy implements StrategyInterface
{
    use DefaultExitRules;

    #[Input(
        description: 'Position size as percentage of capital (not optimised -- fixed per run)',
    )]
    protected float $positionSizePercent = 1.0;

    #[Input(
        description: 'Sizing base: equity (current total equity) or initial (starting capital)',
        choices: ['equity', 'initial']
    )]
    protected string $positionSizingMethod = 'equity';

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
    }

    public function initialize(InitializeData $data): void
    {
        $ohlcv = $data->ohlcv;
        $this->ctx = new IndicatorContext($ohlcv);
        $this->initialCapital = (float) $data->initialCapital;

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

    final public function onBar(BarData $data): array
    {
        $signals = [];
        $currentIndex = $data->cursor->currentIndex;

        $currentPrice = (string) $this->closePrices[$currentIndex];
        $openPosition = $data->portfolio->getOpenPosition($data->symbol);

        if (($this->entrySignals[$currentIndex] ?? false) && $openPosition === null) {
            $stopLoss = $this->calculateStopLoss($currentPrice, $currentIndex);
            $takeProfit = $this->calculateTakeProfit($currentPrice, $currentIndex);
            $capital = $this->positionSizingMethod === 'equity'
                ? (float) $data->portfolio->getTotalEquity([$data->symbol => $currentPrice])
                : $this->initialCapital;
            $positionSize = OrderCalculator::positionSize($capital, $this->positionSizePercent);

            $signals[] = OrderCalculator::entryOrder(
                symbol: $data->symbol,
                positionSize: $positionSize,
                stopLoss: $stopLoss,
                takeProfit: $takeProfit,
            );
        }

        if (($this->exitSignals[$currentIndex] ?? false) && $openPosition !== null) {
            $signals[] = OrderCalculator::exitOrder(
                symbol: $data->symbol,
                quantity: (string) $openPosition->quantity,
            );
        }

        return $signals;
    }

    protected function calculateStopLoss(string $currentPrice, int $currentIndex): string
    {
        return OrderCalculator::stopLoss($currentPrice, $this->stopLossPercent());
    }

    protected function calculateTakeProfit(string $currentPrice, int $currentIndex): string
    {
        return OrderCalculator::takeProfit($currentPrice, $this->takeProfitPercent());
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
