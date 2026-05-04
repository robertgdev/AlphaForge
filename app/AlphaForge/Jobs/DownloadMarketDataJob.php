<?php

namespace App\AlphaForge\Jobs;

use App\AlphaForge\Data\Dto\DownloadRequestDto;
use App\AlphaForge\Data\Exception\DownloadCancelledException;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Service\OhlcvDownloader;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DownloadMarketDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $exchangeId,
        public string $symbol,
        public string $timeframe,
        public Carbon $startDate,
        public Carbon $endDate,
        public bool $forceOverwrite = false,
        public ?string $jobId = null,
    ) {
        $this->jobId = $jobId ?? uniqid('download_', true);
    }

    /**
     * Execute the job.
     */
    public function handle(OhlcvDownloader $downloader): void
    {
        Log::info('Starting market data download', [
            'job_id' => $this->jobId,
            'exchange' => $this->exchangeId,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'start' => $this->startDate->toDateString(),
            'end' => $this->endDate->toDateString(),
        ]);

        try {
            $resultPath = $downloader->download(
                $this->exchangeId,
                $this->symbol,
                $this->timeframe,
                $this->startDate,
                $this->endDate,
                $this->forceOverwrite,
                $this->jobId
            );

            Log::info('Market data download completed successfully', [
                'job_id' => $this->jobId,
                'path' => $resultPath,
            ]);
        } catch (DownloadCancelledException $e) {
            Log::info('Market data download was cancelled', [
                'job_id' => $this->jobId,
                'reason' => $e->getMessage(),
            ]);

            // Don't release back to queue for cancellation
            return;
        } catch (DownloaderException $e) {
            Log::error('Market data download failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Market data download job failed', [
            'job_id' => $this->jobId,
            'exchange' => $this->exchangeId,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            "exchange:{$this->exchangeId}",
            "symbol:{$this->symbol}",
            "timeframe:{$this->timeframe}",
        ];
    }

    /**
     * Create a DTO from the job.
     */
    public function toDto(): DownloadRequestDto
    {
        return new DownloadRequestDto(
            exchangeId: $this->exchangeId,
            symbol: $this->symbol,
            timeframe: $this->timeframe,
            startDate: $this->startDate,
            endDate: $this->endDate,
            forceOverwrite: $this->forceOverwrite
        );
    }
}
