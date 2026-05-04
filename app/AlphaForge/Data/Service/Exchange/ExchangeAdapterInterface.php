<?php

namespace App\AlphaForge\Data\Service\Exchange;

use Carbon\Carbon;

interface ExchangeAdapterInterface
{
    /**
     * Check if this adapter supports the given exchange.
     */
    public function supportsExchange(string $exchangeId): bool;

    /**
     * Fetch OHLCV data from an exchange.
     *
     * @return \Generator<int, array{timestamp: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function fetchOhlcv(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        Carbon $startTime,
        Carbon $endTime,
        ?string $jobId = null
    ): \Generator;

    /**
     * Fetch the first available timestamp for a symbol.
     */
    public function fetchFirstAvailableTimestamp(
        string $exchangeId,
        string $symbol,
        string $timeframe
    ): ?Carbon;
}
