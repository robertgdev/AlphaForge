<?php

namespace App\AlphaForge\Data\Dto;

use Carbon\Carbon;

readonly class DownloadRequestDto
{
    public function __construct(
        public string $exchangeId,
        public string $symbol,
        public string $timeframe,
        public Carbon $startDate,
        public ?Carbon $endDate = null,
        public bool $forceOverwrite = false,
    ) {}

    /**
     * Get the end date, defaulting to now if not set.
     */
    public function getEndDate(): Carbon
    {
        return $this->endDate ?? Carbon::now();
    }
}
