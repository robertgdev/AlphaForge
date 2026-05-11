<?php

namespace App\AlphaForge\Strategy\Model;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Indicator\Model\IndicatorManagerInterface;
use App\AlphaForge\Order\Model\OrderManagerInterface;
use Ds\Map;
use Ds\Vector;

class StrategyContext implements StrategyContextInterface
{
    public ?string $currentSymbol = null;

    public function __construct(
        private IndicatorManagerInterface $indicatorManager,
        private OrderManagerInterface $orderManager,
        private BacktestCursor $cursor,
        /** @var Map<string, Vector<array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}>> */
        private Map $dataframes
    ) {}

    public function getIndicators(): IndicatorManagerInterface
    {
        return $this->indicatorManager;
    }

    public function getOrders(): OrderManagerInterface
    {
        return $this->orderManager;
    }

    public function getCurrentSymbol(): ?string
    {
        return $this->currentSymbol;
    }

    public function getCurrentBarIndex(): int
    {
        return $this->cursor->currentIndex;
    }

    /**
     * @return Map<string, Vector<array{timestamp: int|float, open: float, high: float, low: float, close: float, volume: float}>>
     */
    public function getDataframes(): Map
    {
        return $this->dataframes;
    }
}
