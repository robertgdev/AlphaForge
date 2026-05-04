<?php

namespace App\AlphaForge\Data\Service\Exchange;

use App\AlphaForge\Data\Exception\ExchangeException;
use ccxt\Exchange;

final class ExchangeFactory
{
    /** @var array<string, Exchange> */
    private array $instances = [];

    /**
     * Create or get a cached exchange instance.
     *
     * @throws ExchangeException
     */
    public function create(string $exchangeId): Exchange
    {
        if (isset($this->instances[$exchangeId])) {
            return $this->instances[$exchangeId];
        }

        if (! in_array($exchangeId, Exchange::$exchanges, true)) {
            throw new ExchangeException(sprintf('Exchange "%s" is not supported by CCXT.', $exchangeId));
        }

        $class = "\\ccxt\\{$exchangeId}";
        $this->instances[$exchangeId] = new $class;

        return $this->instances[$exchangeId];
    }

    /**
     * Get all supported exchange IDs.
     *
     * @return array<string>
     */
    public function getSupportedExchanges(): array
    {
        $exchanges = Exchange::$exchanges;
        sort($exchanges);

        return $exchanges;
    }
}
