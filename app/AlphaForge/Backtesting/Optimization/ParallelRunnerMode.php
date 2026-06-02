<?php

namespace App\AlphaForge\Backtesting\Optimization;

enum ParallelRunnerMode: string
{
    case SYNC = 'sync';
    case FORK = 'fork';
    case QUEUE = 'queue';
}
