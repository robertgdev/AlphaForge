<?php

namespace App\AlphaForge\Data\Service;

use App\AlphaForge\Data\Exception\ExchangeException;
use App\AlphaForge\Data\Service\Exchange\ExchangeFactory;
use ccxt\Exchange;
use Illuminate\Support\Facades\Cache;

use function Safe\preg_replace;

readonly class MarketDataService
{
    public function __construct(
        private ExchangeFactory $exchangeFactory
    ) {}

    /**
     * Get all supported exchanges.
     *
     * @return list<string>
     */
    public function getExchanges(): array
    {
        /** @psalm-suppress PossiblyInvalidArgument */
        $exchanges = Exchange::$exchanges;
        sort($exchanges);

        return $exchanges;
    }

    /**
     * Get futures/swap symbols for a specific exchange.
     *
     * @return list<string>
     *
     * @throws ExchangeException
     */
    public function getFuturesSymbols(string $exchangeId): array
    {
        $safeExchangeId = preg_replace('/[^a-zA-Z0-9_.]/', '_', $exchangeId);
        $cacheKey = 'alphaforge.symbols.futures.'.$safeExchangeId;

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($exchangeId) {
            try {
                $exchange = $this->exchangeFactory->create($exchangeId);
                /** @psalm-suppress MixedAssignment, MixedArgument */
                $markets = $exchange->loadMarkets();

                $uniqueSymbols = [];
                /** @psalm-suppress MixedAssignment, MixedObjectFetch, MixedArgument */
                foreach ($markets as $market) {
                    if (isset($market['active'], $market['type'], $market['base'], $market['quote']) && $market['active'] === true) {
                        if ($market['type'] === 'swap' || $market['type'] === 'future') {
                            /** @psalm-suppress MixedArgument, MixedOperand */
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
     * @return list<string>
     *
     * @throws ExchangeException
     */
    public function getAllSymbols(string $exchangeId): array
    {
        $safeExchangeId = preg_replace('/[^a-zA-Z0-9_.]/', '_', $exchangeId);
        $cacheKey = 'alphaforge.symbols.all.'.$safeExchangeId;

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($exchangeId) {
            try {
                $exchange = $this->exchangeFactory->create($exchangeId);
                /** @psalm-suppress MixedAssignment, MixedArgument */
                $markets = $exchange->loadMarkets();

                $symbols = [];
                /** @psalm-suppress MixedAssignment, MixedObjectFetch, MixedOperand */
                foreach ($markets as $market) {
                    if (isset($market['active']) && $market['active'] === true && isset($market['symbol'])) {
                        /** @psalm-suppress MixedAssignment */
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
