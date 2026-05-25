<?php

namespace App\AlphaForge\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Provides common argument parsing and display helpers for commands that operate
 * on exchange/market/timeframe market data.
 *
 * @mixin Command
 */
trait ParsesMarketDataArgs
{
    /**
     * Parse the exchange argument (lowercased).
     */
    protected function parseExchange(): string
    {
        return strtolower($this->argument('exchange'));
    }

    /**
     * Parse the market argument (uppercased).
     */
    protected function parseMarket(): string
    {
        return strtoupper($this->argument('market'));
    }

    /**
     * Parse the timeframe argument (as-is).
     */
    protected function parseTimeframe(): string
    {
        return $this->argument('timeframe');
    }

    /**
     * Display the standard market data header with optional additional fields.
     *
     * @param  array<string, string>  $extraFields  Additional key/value pairs to display
     */
    protected function displayMarketDataHeader(
        string $exchange,
        string $market,
        string $timeframe,
        array $extraFields = []
    ): void {
        $this->components->twoColumnDetail('Exchange', $exchange);
        $this->components->twoColumnDetail('Market', $market);
        $this->components->twoColumnDetail('Timeframe', $timeframe);

        foreach ($extraFields as $key => $value) {
            $this->components->twoColumnDetail($key, $value);
        }

        $this->newLine();
    }
}
