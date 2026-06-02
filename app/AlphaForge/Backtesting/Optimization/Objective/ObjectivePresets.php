<?php

namespace App\AlphaForge\Backtesting\Optimization\Objective;

class ObjectivePresets
{
    public static function sharpeFocused(): CompositeObjective
    {
        return new CompositeObjective(
            [
                new ObjectiveWeight('sharpe_ratio', 1.0, minClamp: -5.0, maxClamp: 5.0),
                new ObjectiveWeight('max_drawdown_percent', -0.3, maxClamp: 0.0),
            ],
            'sharpe_focused',
        );
    }

    public static function balanced(): CompositeObjective
    {
        return new CompositeObjective(
            [
                new ObjectiveWeight('total_return_percent', 1.0),
                new ObjectiveWeight('max_drawdown_percent', -0.5, maxClamp: 0.0),
                new ObjectiveWeight('sharpe_ratio', 5.0, minClamp: -5.0, maxClamp: 5.0),
                new ObjectiveWeight('win_rate', 0.5, maxClamp: 1.0),
            ],
            'balanced',
        );
    }

    public static function conservative(): CompositeObjective
    {
        return new CompositeObjective(
            [
                new ObjectiveWeight('max_drawdown_percent', -2.0, maxClamp: 0.0),
                new ObjectiveWeight('profit_factor', 1.0, maxClamp: 10.0),
                new ObjectiveWeight('sortino_ratio', 5.0, minClamp: -5.0, maxClamp: 5.0),
            ],
            'conservative',
        );
    }

    public static function aggressive(): CompositeObjective
    {
        return new CompositeObjective(
            [
                new ObjectiveWeight('total_return_percent', 2.0),
                new ObjectiveWeight('sharpe_ratio', 5.0, minClamp: -5.0, maxClamp: 5.0),
                new ObjectiveWeight('max_drawdown_percent', -0.2, maxClamp: 0.0),
            ],
            'aggressive',
        );
    }

    public static function all(): array
    {
        return [
            'sharpe_focused' => self::sharpeFocused(),
            'balanced' => self::balanced(),
            'conservative' => self::conservative(),
            'aggressive' => self::aggressive(),
        ];
    }
}