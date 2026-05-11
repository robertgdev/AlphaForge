<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

class ObjectiveFactory
{
    public static function create(string|ObjectiveFunctionInterface $objective): ObjectiveFunctionInterface
    {
        if ($objective instanceof ObjectiveFunctionInterface) {
            return $objective;
        }

        $presets = ObjectivePresets::all();
        if (isset($presets[$objective])) {
            return $presets[$objective];
        }

        return new SingleMetricObjective($objective);
    }
}
