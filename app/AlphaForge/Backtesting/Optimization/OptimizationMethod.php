<?php

namespace App\AlphaForge\Backtesting\Optimization;

enum OptimizationMethod: string
{
    case GRID = 'grid';
    case RANDOM = 'random';
    case GENETIC = 'genetic';
}
