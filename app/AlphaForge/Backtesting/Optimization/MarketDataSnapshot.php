<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Common\Enum\TimeframeEnum;

use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\unserialize;

readonly class MarketDataSnapshot
{
    /**
     * @param  array<string, array{data: array, symbol: string, timeframe: TimeframeEnum}>  $signalData
     * @param  array<string, array{data: array, symbol: string, timeframe: TimeframeEnum}>|null  $executionData
     */
    public function __construct(
        public array $signalData,
        public ?array $executionData = null,
    ) {}

    public function saveToFile(string $path): void
    {
        file_put_contents($path, serialize($this));
    }

    public static function fromSerializedFile(string $path): self
    {
        $raw = file_get_contents($path);
        $snapshot = unserialize($raw);

        if (! $snapshot instanceof self) {
            throw new \RuntimeException("Corrupted MarketDataSnapshot file at {$path}");
        }

        return $snapshot;
    }
}
