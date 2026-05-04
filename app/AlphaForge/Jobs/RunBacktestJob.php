<?php

namespace App\AlphaForge\Jobs;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunBacktestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public BacktestRun $backtestRun
    ) {
        $this->onQueue(config('alphaforge.queues.backtest', 'backtests'));
    }

    /**
     * Execute the job.
     */
    public function handle(BacktestRunService $service): void
    {
        $service->execute($this->backtestRun);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        // Failure is already handled by BacktestRunService::execute()
        // This method exists only for any additional cleanup if needed
    }
}
