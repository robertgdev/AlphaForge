<?php

use App\AlphaForge\Strategy\Concretes\SmaCrossoverStrategy;

describe('SmaCrossoverStrategy', function () {
    beforeEach(function () {
        $this->strategy = new SmaCrossoverStrategy;
    });

    describe('configure', function () {
        it('applies fastPeriod from inputs', function () {
            $this->strategy->configure(['fastPeriod' => 20]);

            $ref = new ReflectionProperty($this->strategy, 'fastPeriod');
            $ref->setAccessible(true);
            expect($ref->getValue($this->strategy))->toBe(20);
        });

        it('applies slowPeriod from inputs', function () {
            $this->strategy->configure(['slowPeriod' => 100]);

            $ref = new ReflectionProperty($this->strategy, 'slowPeriod');
            $ref->setAccessible(true);
            expect($ref->getValue($this->strategy))->toBe(100);
        });

        it('applies stopLossPercent from inputs', function () {
            $this->strategy->configure(['stopLossPercent' => 3.0]);

            $ref = new ReflectionProperty($this->strategy, 'stopLossPercent');
            $ref->setAccessible(true);
            expect($ref->getValue($this->strategy))->toBe(3.0);
        });

        it('applies takeProfitPercent from inputs', function () {
            $this->strategy->configure(['takeProfitPercent' => 15.0]);

            $ref = new ReflectionProperty($this->strategy, 'takeProfitPercent');
            $ref->setAccessible(true);
            expect($ref->getValue($this->strategy))->toBe(15.0);
        });

        it('applies stakeAmount from inputs', function () {
            $this->strategy->configure(['stakeAmount' => '5000']);

            $ref = new ReflectionProperty($this->strategy, 'stakeAmount');
            $ref->setAccessible(true);
            expect($ref->getValue($this->strategy))->toBe('5000');
        });

        it('keeps defaults when inputs are not provided', function () {
            $this->strategy->configure([]);

            $fastRef = new ReflectionProperty($this->strategy, 'fastPeriod');
            $fastRef->setAccessible(true);
            $slowRef = new ReflectionProperty($this->strategy, 'slowPeriod');
            $slowRef->setAccessible(true);

            expect($fastRef->getValue($this->strategy))->toBe(10)
                ->and($slowRef->getValue($this->strategy))->toBe(50);
        });

        it('applies all inputs at once', function () {
            $this->strategy->configure([
                'fastPeriod' => 15,
                'slowPeriod' => 60,
                'stopLossPercent' => 2.5,
                'takeProfitPercent' => 8.0,
                'stakeAmount' => '2000',
            ]);

            $fastRef = new ReflectionProperty($this->strategy, 'fastPeriod');
            $fastRef->setAccessible(true);
            $slowRef = new ReflectionProperty($this->strategy, 'slowPeriod');
            $slowRef->setAccessible(true);
            $slRef = new ReflectionProperty($this->strategy, 'stopLossPercent');
            $slRef->setAccessible(true);
            $tpRef = new ReflectionProperty($this->strategy, 'takeProfitPercent');
            $tpRef->setAccessible(true);
            $stakeRef = new ReflectionProperty($this->strategy, 'stakeAmount');
            $stakeRef->setAccessible(true);

            expect($fastRef->getValue($this->strategy))->toBe(15)
                ->and($slowRef->getValue($this->strategy))->toBe(60)
                ->and($slRef->getValue($this->strategy))->toBe(2.5)
                ->and($tpRef->getValue($this->strategy))->toBe(8.0)
                ->and($stakeRef->getValue($this->strategy))->toBe('2000');
        });
    });

    describe('onBar', function () {
        it('returns empty signals when initialize has not been called', function () {
            $closes = Mockery::mock(\App\AlphaForge\Common\Model\Series::class);
            $closes->shouldReceive('getVector')->andReturn(new \Ds\Vector(array_map('strval', range(100, 150))));

            $ohlcv = Mockery::mock(\App\AlphaForge\Common\Model\OhlcvSeries::class);
            $ohlcv->shouldReceive('getCloses')->andReturn($closes);

            $cursor = new \App\AlphaForge\Backtesting\Model\BacktestCursor;
            $cursor->currentIndex = 5;

            $portfolio = new \App\AlphaForge\Order\Model\PortfolioManager('10000');

            $data = [
                'ohlcv' => $ohlcv,
                'cursor' => $cursor,
                'portfolio' => $portfolio,
                'symbol' => 'BTC/USDT',
            ];

            $result = $this->strategy->onBar($data);

            expect($result)->toBe([]);
        });
    });

    describe('implements StrategyInterface', function () {
        it('implements StrategyInterface', function () {
            expect($this->strategy)->toBeInstanceOf(\App\AlphaForge\Strategy\StrategyInterface::class);
        });
    });

    describe('AsStrategy attribute', function () {
        it('has AsStrategy attribute with correct alias', function () {
            $ref = new ReflectionClass($this->strategy);
            $attrs = $ref->getAttributes(\App\AlphaForge\Strategy\Attribute\AsStrategy::class);

            expect($attrs)->toHaveCount(1);

            $asStrategy = $attrs[0]->newInstance();
            expect($asStrategy->alias)->toBe('sma_crossover')
                ->and($asStrategy->name)->toBe('SMA Crossover')
                ->and($asStrategy->timeframe)->toBe(\App\AlphaForge\Common\Enum\TimeframeEnum::H1);
        });
    });

    describe('Input attributes', function () {
        it('has Input attributes on properties', function () {
            $ref = new ReflectionClass($this->strategy);
            $inputProps = [];

            foreach ($ref->getProperties() as $property) {
                $inputAttrs = $property->getAttributes(\App\AlphaForge\Strategy\Attribute\Input::class);
                if (! empty($inputAttrs)) {
                    $inputProps[] = $property->getName();
                }
            }

            expect($inputProps)->toContain('fastPeriod')
                ->and($inputProps)->toContain('slowPeriod')
                ->and($inputProps)->toContain('stopLossPercent')
                ->and($inputProps)->toContain('takeProfitPercent')
                ->and($inputProps)->toContain('stakeAmount');
        });
    });
});
