<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Common\Model\Series;
use App\AlphaForge\Order\Model\PortfolioManager;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Concretes\SmaCrossoverStrategy;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\StrategyInterface;
use Ds\Vector;

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

        it('applies positionSizePercent from inputs', function () {
            $this->strategy->configure(['positionSizePercent' => 2.5]);

            $ref = new ReflectionProperty($this->strategy, 'positionSizePercent');
            $ref->setAccessible(true);
            expect($ref->getValue($this->strategy))->toBe(2.5);
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
                'positionSizePercent' => 2.0,
            ]);

            $fastRef = new ReflectionProperty($this->strategy, 'fastPeriod');
            $fastRef->setAccessible(true);
            $slowRef = new ReflectionProperty($this->strategy, 'slowPeriod');
            $slowRef->setAccessible(true);
            $slRef = new ReflectionProperty($this->strategy, 'stopLossPercent');
            $slRef->setAccessible(true);
            $tpRef = new ReflectionProperty($this->strategy, 'takeProfitPercent');
            $tpRef->setAccessible(true);
            $psRef = new ReflectionProperty($this->strategy, 'positionSizePercent');
            $psRef->setAccessible(true);

            expect($fastRef->getValue($this->strategy))->toBe(15)
                ->and($slowRef->getValue($this->strategy))->toBe(60)
                ->and($slRef->getValue($this->strategy))->toBe(2.5)
                ->and($tpRef->getValue($this->strategy))->toBe(8.0)
                ->and($psRef->getValue($this->strategy))->toBe(2.0);
        });
    });

    describe('onBar', function () {
        it('returns empty signals when initialize has not been called', function () {
            $closes = Mockery::mock(Series::class);
            $closes->shouldReceive('getVector')->andReturn(new Vector(array_map('strval', range(100, 150))));

            $ohlcv = Mockery::mock(OhlcvSeries::class);
            $ohlcv->shouldReceive('getCloses')->andReturn($closes);

            $cursor = new BacktestCursor;
            $cursor->currentIndex = 5;

            $portfolio = new PortfolioManager('10000');

            $data = new BarData(
                cursor: $cursor,
                ohlcv: $ohlcv,
                portfolio: $portfolio,
                symbol: 'BTC/USDT',
            );

            $result = $this->strategy->onBar($data);

            expect($result)->toBe([]);
        });
    });

    describe('implements StrategyInterface', function () {
        it('implements StrategyInterface', function () {
            expect($this->strategy)->toBeInstanceOf(StrategyInterface::class);
        });
    });

    describe('AsStrategy attribute', function () {
        it('has AsStrategy attribute with correct alias', function () {
            $ref = new ReflectionClass($this->strategy);
            $attrs = $ref->getAttributes(AsStrategy::class);

            expect($attrs)->toHaveCount(1);

            $asStrategy = $attrs[0]->newInstance();
            expect($asStrategy->alias)->toBe('sma_crossover')
                ->and($asStrategy->name)->toBe('SMA Crossover')
                ->and($asStrategy->timeframe)->toBe(TimeframeEnum::H1);
        });
    });

    describe('Input attributes', function () {
        it('has Input attributes on properties', function () {
            $ref = new ReflectionClass($this->strategy);
            $inputProps = [];

            foreach ($ref->getProperties() as $property) {
                $inputAttrs = $property->getAttributes(Input::class);
                if (! empty($inputAttrs)) {
                    $inputProps[] = $property->getName();
                }
            }

            expect($inputProps)->toContain('fastPeriod')
                ->and($inputProps)->toContain('slowPeriod')
                ->and($inputProps)->toContain('stopLossPercent')
                ->and($inputProps)->toContain('takeProfitPercent')
                ->and($inputProps)->not->toContain('positionSizePercent');
        });
    });
});
