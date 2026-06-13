<?php

namespace App\AlphaForge\Console\Commands\Concerns;

use App\AlphaForge\Common\Util\MemoryHelper;

trait DebugMemory
{
    private function debugMemory(): void
    {
        if ($this->option('debug')) {
            $peak = memory_get_peak_usage(true);
            $formatted = MemoryHelper::formatBytes($peak);
            $this->newLine();
            $this->line("<fg=gray>debug: peak memory {$formatted}</>");
        }
    }
}
