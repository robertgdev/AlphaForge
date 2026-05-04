<?php

use App\Stochastix\Data\Exception\ExchangeException;
use App\Stochastix\Data\Service\Exchange\ExchangeFactory;
use ccxt\binance;
use ccxt\kraken;

describe('ExchangeFactory', function () {
    it('can create exchange instances for valid exchange IDs', function () {
        $factory = new ExchangeFactory;

        $exchange = $factory->create('binance');

        expect($exchange)->toBeInstanceOf(binance::class);
    });

    it('throws exception for invalid exchange ID', function () {
        $factory = new ExchangeFactory;

        expect(fn () => $factory->create('invalid_exchange_12345'))
            ->toThrow(ExchangeException::class);
    });

    it('caches exchange instances', function () {
        $factory = new ExchangeFactory;

        $exchange1 = $factory->create('kraken');
        $exchange2 = $factory->create('kraken');

        expect($exchange1)->toBe($exchange2);
    });

    it('can create different exchange instances', function () {
        $factory = new ExchangeFactory;

        $binance = $factory->create('binance');
        $kraken = $factory->create('kraken');

        expect($binance)->toBeInstanceOf(binance::class)
            ->and($kraken)->toBeInstanceOf(kraken::class)
            ->and($binance)->not->toBe($kraken);
    });
});
