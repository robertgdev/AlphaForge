<?php

namespace App\AlphaForge\Console\Concerns;

use App\AlphaForge\Common\Service\DateParsingService;
use App\AlphaForge\Events\DownloadProgress;
use Carbon\Carbon;

use function Laravel\Prompts\error;

/**
 * Provides download progress handling for commands that invoke OhlcvDownloader.
 *
 * @mixin Command
 */
trait HandlesDownloadProgress
{
    use HasProgressBar;

    protected int $totalDuration = 0;

    /**
     * Parse an optional end date string, defaulting to now if null.
     */
    protected function parseEndDate(?string $enddate, DateParsingService $dateParsingService): Carbon
    {
        return $enddate ? $dateParsingService->parseDate($enddate) : Carbon::now();
    }

    /**
     * Handle a DownloadProgress event by updating the progress bar.
     */
    protected function handleProgressEvent(DownloadProgress $event): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $percentComplete = $this->totalDuration > 0
            ? (int) round(($event->currentProgress / ($this->totalDuration * 1000)) * 100)
            : 0;

        $percentComplete = max(0, min(100, $percentComplete));

        $this->progressBar->setProgress($percentComplete);

        $dateStr = gmdate('Y-m-d H:i:s', $event->lastTimestamp);
        $this->progressBar->setMessage("Fetching: {$dateStr} ({$event->recordsFetchedInBatch} records)");
    }

    /**
     * Validate that a start date is strictly before an end date.
     */
    protected function validateDateRange(Carbon $start, Carbon $end): bool
    {
        if ($start->greaterThanOrEqualTo($end)) {
            error('Start date must be before end date.');

            return false;
        }

        return true;
    }
}
