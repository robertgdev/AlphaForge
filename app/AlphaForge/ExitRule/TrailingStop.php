<?php

namespace App\AlphaForge\ExitRule;

use App\AlphaForge\Indicator\Model\IndicatorContext;
use App\AlphaForge\TimeSeries\TimeSeriesInterface;

class TrailingStop implements PriceBasedExitRule
{
    private ?TimeSeriesInterface $atrSeries = null;

    private ?float $percentDistance = null;

    private float $multiplier = 2.0;

    private function __construct() {}

    public static function percent(float $percent): self
    {
        $rule = new self;
        $rule->percentDistance = $percent / 100;

        return $rule;
    }

    public static function atr(IndicatorContext $ctx, int $period = 14, float $multiplier = 2.0): self
    {
        $rule = new self;
        $rule->atrSeries = $ctx->atr($period);
        $rule->multiplier = $multiplier;

        return $rule;
    }

    public function evaluate(ExitContext $context): ?ExitTrigger
    {
        $distance = $this->getDistance($context);

        if ($context->position->direction === 'long') {
            $trail = $context->highestSinceEntry - $distance;
            if ($context->low <= $trail) {
                return new ExitTrigger('trailing_stop', $trail);
            }
        } else {
            $trail = $context->lowestSinceEntry + $distance;
            if ($context->high >= $trail) {
                return new ExitTrigger('trailing_stop', $trail);
            }
        }

        return null;
    }

    private function getDistance(ExitContext $context): float
    {
        if ($this->percentDistance !== null) {
            return $context->close * $this->percentDistance;
        }

        $atrValue = $this->atrSeries->get($context->barIndex) ?? $context->close * 0.02;

        return $atrValue * $this->multiplier;
    }
}
