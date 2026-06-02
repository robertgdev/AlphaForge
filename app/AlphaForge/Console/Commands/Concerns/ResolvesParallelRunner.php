<?php

namespace App\AlphaForge\Console\Commands\Concerns;

use App\AlphaForge\Backtesting\Optimization\ParallelRunnerMode;

use function Safe\shell_exec;

trait ResolvesParallelRunner
{
    private function resolveRunnerMode(string $runnerValue): ParallelRunnerMode
    {
        $runnerValue = strtolower($runnerValue);

        $mode = ParallelRunnerMode::tryFrom($runnerValue);

        if ($mode === null) {
            $this->warn("Unknown --runner value '{$runnerValue}'. Valid: sync, fork, queue. Falling back to fork.");

            return ParallelRunnerMode::FORK;
        }

        if ($mode === ParallelRunnerMode::FORK && ! function_exists('pcntl_fork')) {
            $this->warn('ext-pcntl is not available. Falling back to --runner=sync.');

            return ParallelRunnerMode::SYNC;
        }

        if ($mode === ParallelRunnerMode::QUEUE) {
            $this->warn('--runner=queue is not yet implemented. Falling back to --runner=fork.');
            if (! function_exists('pcntl_fork')) {
                return ParallelRunnerMode::SYNC;
            }

            return ParallelRunnerMode::FORK;
        }

        return $mode;
    }

    private function resolveWorkerCount(string $workersValue): int
    {
        if ($workersValue === 'auto') {
            return $this->detectCpuCores();
        }

        $count = (int) $workersValue;

        if ($count < 1) {
            $this->warn("Invalid --workers value '{$workersValue}'. Using auto-detection.");

            return $this->detectCpuCores();
        }

        return $count;
    }

    private function detectCpuCores(): int
    {
        $cores = $this->rawCpuCores();
        $ratio = (float) config('alphaforge.optimization.cpu_ratio', 0.8);

        return max(1, (int) round($cores * $ratio));
    }

    private function rawCpuCores(): int
    {
        if (function_exists('swoole_cpu_num')) {
            $count = swoole_cpu_num();

            return $count > 0 ? $count : 4;
        }

        $nproc = (int) trim(shell_exec('nproc 2>/dev/null') ?: '');
        if ($nproc > 0) {
            return $nproc;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $sysctl = (int) trim(shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: '');
            if ($sysctl > 0) {
                return $sysctl;
            }
        }

        return 4;
    }
}
