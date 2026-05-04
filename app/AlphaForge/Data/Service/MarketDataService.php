<?php

namespace App\AlphaForge\Data\Service;

use App\AlphaForge\Data\Exception\ExchangeException;
use App\AlphaForge\Data\Service\Exchange\ExchangeFactory;
use ccxt\Exchange;
use Illuminate\Support\Facades\Cache;

readonly class MarketDataService
{
    public function __construct(
        private ExchangeFactory $exchangeFactory
    ) {}

    /**
     * Get all supported exchanges.
     *
     * @return string[]
     */
    public function getExchanges(): array
    {
        $exchanges = Exchange::$exchanges;
        sort($exchanges);

        return $exchanges;
    }

    /**
     * Get futures/swap symbols for a specific exchange.
     *
     * @return string[]
     *
     * @throws ExchangeException
     */
    public function getFuturesSymbols(string $exchangeId): array
    {
        $cacheKey = 'stochastix.symbols.futures.'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $exchangeId);

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($exchangeId) {
            try {
                $exchange = $this->exchangeFactory->create($exchangeId);
                $markets = $exchange->loadMarkets();

                $uniqueSymbols = [];
                foreach ($markets as $market) {
                    if (isset($market['active'], $market['type'], $market['base'], $market['quote']) && $market['active'] === true) {
                        if ($market['type'] === 'swap' || $market['type'] === 'future') {
                            $symbol = $market['base'].'/'.$market['quote'];
                            $uniqueSymbols[$symbol] = true;
                        }
                    }
                }

                $futuresSymbols = array_keys($uniqueSymbols);
                sort($futuresSymbols);

                return $futuresSymbols;
            } catch (\Throwable $e) {
                throw new ExchangeException("Failed to fetch symbols for exchange '{$exchangeId}': ".$e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Get all symbols (including spot) for a specific exchange.
     *
     * @return string[]
     *
     * @throws ExchangeException
     */
    public function getAllSymbols(string $exchangeId): array
    {
        $cacheKey = 'stochastix.symbols.all.'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $exchangeId);

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($exchangeId) {
            try {
                $exchange = $this->exchangeFactory->create($exchangeId);
                $markets = $exchange->loadMarkets();

                $symbols = [];
                foreach ($markets as $market) {
                    if (isset($market['active']) && $market['active'] === true && isset($market['symbol'])) {
                        $symbols[] = $market['symbol'];
                    }
                }

                sort($symbols);

                return $symbols;
            } catch (\Throwable $e) {
                throw new ExchangeException("Failed to fetch symbols for exchange '{$exchangeId}': ".$e->getMessage(), 0, $e);
            }
        });
    }
}
