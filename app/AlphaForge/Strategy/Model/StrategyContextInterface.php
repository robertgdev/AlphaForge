<?php

namespace App\AlphaForge\Strategy\Model;

use App\AlphaForge\Indicator\Model\IndicatorManagerInterface;
use App\AlphaForge\Order\Model\OrderManagerInterface;

interface StrategyContextInterface
{
    public function getIndicators(): IndicatorManagerInterface;

    public function getOrders(): OrderManagerInterface;

    public function getCurrentSymbol(): ?string;

    public function getCurrentBarIndex(): int;
}
