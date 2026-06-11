<?php

namespace App\AlphaForge\Services;

use App\AlphaForge\Common\Enum\TimeframeEnum;

class MarketDataPathBuilder
{
    private const KNOWN_QUOTES = [
        'USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD', 'DAI',
        'BTC', 'ETH', 'EUR', 'GBP', 'JPY', 'AUD', 'BNB',
    ];

    public function __construct(
        private readonly string $marketDataPath,
    ) {}

    public function build(
        string $exchange,
        string $symbolOrMarket,
        string|TimeframeEnum $timeframe,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): string {
        $tf = $timeframe instanceof TimeframeEnum ? $timeframe->value : $timeframe;

        $basePath = sprintf(
            '%s/%s/%s/%s',
            rtrim($this->marketDataPath, '/'),
            strtolower($exchange),
            $this->sanitizeSymbol($symbolOrMarket),
            $tf,
        );

        return match ($dataType) {
            'heikenashi' => $basePath.'/heikenashi.stchx',
            'renko' => $basePath.'/renko_'.$this->formatBrickSize($brickSize ?? 10.0).'.stchx',
            'atr_renko' => $basePath.'/renko_atr_'.($atrPeriod ?? 14).'.stchx',
            default => $basePath.'/ohlcv.stchx',
        };
    }

    public function sanitizeSymbol(string $symbol): string
    {
        if (! str_contains($symbol, '/') && ! str_contains($symbol, '_')) {
            foreach (self::KNOWN_QUOTES as $quote) {
                if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                    $symbol = substr($symbol, 0, -strlen($quote)).'/'.$quote;
                    break;
                }
            }
        }

        return str_replace('/', '_', strtoupper($symbol));
    }

    private function formatBrickSize(float $brickSize): string
    {
        if (floor($brickSize) === $brickSize) {
            return (string) (int) $brickSize;
        }

        return str_replace('.', '_', (string) $brickSize);
    }
}