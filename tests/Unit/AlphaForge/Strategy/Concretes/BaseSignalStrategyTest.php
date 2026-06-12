<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Common\Model\Series;
use App\AlphaForge\Condition\ConditionInterface;
use App\AlphaForge\Order\Dto\PositionDto;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Order\Model\PortfolioManager;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Concretes\BaseSignalStrategy;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\Dto\InitializeData;
use App\AlphaForge\Strategy\StrategyInterface;
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;

class TestSignalStrategy extends BaseSignalStrategy
{
    #[Input(description: 'Test int parameter')]
    private int $testPeriod = 10;

    #[Input(description: 'Test float parameter')]
    private float $testThreshold = 5.0;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 3.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 6.0;

    protected function stopLossPercent(): float
    {
        return $this->stopLossPercent;
    }

    protected function takeProfitPercent(): float
    {
        return $this->takeProfitPercent;
    }

    protected function minBars(): int
    {
        return $this->testPeriod;
    }

    protected function strategyName(): string
    {
        return 'Test Strategy';
    }

    protected function computeSignals(OhlcvSeries $ohlcv): void {}
}

function makeOhlcvMock(int $barCount = 100, array $closeValues = []): OhlcvSeries
{
    if (empty($closeValues)) {
        $closeValues = range(100, 100 + $barCount - 1);
    }

    $closeVector = new Vector($closeValues);

    $closes = Mockery::mock(Series::class);
    $closes->shouldReceive('getVector')->andReturn($closeVector);

    $timestamps = Mockery::mock(Series::class);
    $timestamps->shouldReceive('count')->andReturn($barCount);

    $ohlcv = Mockery::mock(OhlcvSeries::class);
    $ohlcv->shouldReceive('getCloses')->andReturn($closes);
    $ohlcv->shouldReceive('getTimestamps')->andReturn($timestamps);

    return $ohlcv;
}

function makeEntryCondition(bool $entryAt10 = false): ConditionInterface
{
    $condition = Mockery::mock(ConditionInterface::class);
    $signals = array_fill(0, 100, false);
    if ($entryAt10) {
        $signals[10] = true;
    }
    $condition->shouldReceive('evaluateAll')->andReturn($signals);

    return $condition;
}

function makeExitCondition(bool $exitAt10 = false): ConditionInterface
{
    $condition = Mockery::mock(ConditionInterface::class);
    $signals = array_fill(0, 100, false);
    if ($exitAt10) {
        $signals[10] = true;
    }
    $condition->shouldReceive('evaluateAll')->andReturn($signals);

    return $condition;
}

function makeBarDataMock(int $currentIndex = 10, ?PortfolioManager $portfolio = null, string $symbol = 'BTC/USDT'): BarData
{
    return new BarData(
        cursor: tap(new BacktestCursor, fn ($c) => $c->currentIndex = $currentIndex),
        ohlcv: makeOhlcvMock(),
        portfolio: $portfolio ?? new PortfolioManager('10000'),
        symbol: $symbol,
    );
}

function makeOpenPositionMock(string $symbol = 'BTC/USDT', string $quantity = '0.01'): PositionDto
{
    return new PositionDto(
        id: 'pos_test1',
        symbol: $symbol,
        direction: 'long',
        quantity: $quantity,
        entryPrice: '100',
        entryTime: Carbon::now(),
        realizedPnl: '0',
    );
}

function makePortfolioWithOpenPosition(string $symbol = 'BTC/USDT', string $quantity = '0.01'): PortfolioManager
{
    $position = makeOpenPositionMock($symbol, $quantity);
    $portfolio = new PortfolioManager('10000');

    $ref = new ReflectionProperty($portfolio, 'openPositions');
    $ref->setAccessible(true);

    /** @var Map $map */
    $map = $ref->getValue($portfolio);
    $map->put($symbol, $position);

    return $portfolio;
}

function setProtected(object $object, string $property, mixed $value): void
{
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(true);
    $ref->setValue($object, $value);
}

function getProtected(object $object, string $property): mixed
{
    $ref = new ReflectionProperty($object, $property);
    $ref->setAccessible(true);

    return $ref->getValue($object);
}

describe('BaseSignalStrategy', function () {
    beforeEach(function () {
        $this->strategy = new TestSignalStrategy;
    });

    describe('configure', function () {
        it('applies #[Input] properties from the inputs array', function () {
            $this->strategy->configure(['testPeriod' => 25]);

            expect(getProtected($this->strategy, 'testPeriod'))->toBe(25);
        });

        it('casts string values to int for int-typed properties', function () {
            $this->strategy->configure(['testPeriod' => '42']);

            expect(getProtected($this->strategy, 'testPeriod'))->toBe(42);
        });

        it('casts integer values to float for float-typed properties', function () {
            $this->strategy->configure(['testThreshold' => 3]);

            expect(getProtected($this->strategy, 'testThreshold'))->toBe(3.0);
        });

        it('applies stopLossPercent from inputs', function () {
            $this->strategy->configure(['stopLossPercent' => 5.0]);

            expect(getProtected($this->strategy, 'stopLossPercent'))->toBe(5.0);
        });

        it('applies takeProfitPercent from inputs', function () {
            $this->strategy->configure(['takeProfitPercent' => 12.0]);

            expect(getProtected($this->strategy, 'takeProfitPercent'))->toBe(12.0);
        });

        it('applies positionSizePercent from inputs', function () {
            $this->strategy->configure(['positionSizePercent' => 2.0]);

            expect(getProtected($this->strategy, 'positionSizePercent'))->toBe(2.0);
        });

        it('applies positionSizingMethod from inputs', function () {
            $this->strategy->configure(['positionSizingMethod' => 'initial']);

            expect(getProtected($this->strategy, 'positionSizingMethod'))->toBe('initial');
        });

        it('keeps defaults when no inputs are provided', function () {
            $this->strategy->configure([]);

            expect(getProtected($this->strategy, 'testPeriod'))->toBe(10);
        });

        it('ignores keys not matching any #[Input] property or positionSizePercent', function () {
            $this->strategy->configure(['unknownParam' => 999]);

            expect(getProtected($this->strategy, 'testPeriod'))->toBe(10);
        });

        it('applies all inputs at once', function () {
            $this->strategy->configure([
                'testPeriod' => 20,
                'testThreshold' => 7.5,
                'stopLossPercent' => 4.0,
                'takeProfitPercent' => 10.0,
                'positionSizePercent' => 3.0,
                'positionSizingMethod' => 'initial',
            ]);

            expect(getProtected($this->strategy, 'testPeriod'))->toBe(20)
                ->and(getProtected($this->strategy, 'testThreshold'))->toBe(7.5)
                ->and(getProtected($this->strategy, 'stopLossPercent'))->toBe(4.0)
                ->and(getProtected($this->strategy, 'takeProfitPercent'))->toBe(10.0)
                ->and(getProtected($this->strategy, 'positionSizePercent'))->toBe(3.0)
                ->and(getProtected($this->strategy, 'positionSizingMethod'))->toBe('initial');
        });
    });

    describe('initialize', function () {
        it('sets ctx, totalBars, and closePrices from OHLCV data', function () {
            $ohlcv = makeOhlcvMock(100);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition());
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv));

            expect(getProtected($this->strategy, 'totalBars'))->toBe(100)
                ->and(getProtected($this->strategy, 'closePrices'))->toHaveCount(100)
                ->and(getProtected($this->strategy, 'ctx'))->not->toBeNull();
        });

        it('throws when total bars is less than minBars', function () {
            $ohlcv = makeOhlcvMock(5);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition());
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv));
        })->throws(RuntimeException::class, 'Insufficient data for Test Strategy');

        it('uses initialCapital from InitializeData', function () {
            $ohlcv = makeOhlcvMock(100);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition());
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv, initialCapital: '50000'));

            expect(getProtected($this->strategy, 'initialCapital'))->toBe(50000.0);
        });

        it('pre-evaluates entry and exit signals from conditions', function () {
            $ohlcv = makeOhlcvMock(100);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition(entryAt10: true));
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv));

            $entrySignals = getProtected($this->strategy, 'entrySignals');
            $exitSignals = getProtected($this->strategy, 'exitSignals');

            expect($entrySignals[10])->toBeTrue()
                ->and($entrySignals[0])->toBeFalse()
                ->and($exitSignals[10])->toBeFalse();
        });
    });

    describe('onBar', function () {
        beforeEach(function () {
            $ohlcv = makeOhlcvMock(100);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition(entryAt10: true));
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv));
        });

        it('returns empty signals when no entry signal at current index', function () {
            $portfolio = new PortfolioManager('10000');
            $data = makeBarDataMock(currentIndex: 5, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toBe([]);
        });

        it('generates LONG entry order when entry signal triggers and no position', function () {
            $portfolio = new PortfolioManager('10000');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toHaveCount(1);

            $signal = $result[0];
            expect($signal->symbol)->toBe('BTC/USDT')
                ->and($signal->direction)->toBe(DirectionEnum::LONG)
                ->and($signal->orderType)->toBe(OrderTypeEnum::Market)
                ->and($signal->stakeAmount)->not->toBeNull()
                ->and($signal->stopLoss)->not->toBeNull()
                ->and($signal->takeProfit)->not->toBeNull();
        });

        it('skips entry when position already open', function () {
            $portfolio = makePortfolioWithOpenPosition();
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toBe([]);
        });

        it('generates SHORT exit order when exit signal triggers with open position', function () {
            $entrySignals = getProtected($this->strategy, 'entrySignals');
            $entrySignals[10] = false;
            setProtected($this->strategy, 'entrySignals', $entrySignals);

            $exitSignals = getProtected($this->strategy, 'exitSignals');
            $exitSignals[10] = true;
            setProtected($this->strategy, 'exitSignals', $exitSignals);

            $portfolio = makePortfolioWithOpenPosition(quantity: '0.001');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toHaveCount(1);

            $signal = $result[0];
            expect($signal->symbol)->toBe('BTC/USDT')
                ->and($signal->direction)->toBe(DirectionEnum::SHORT)
                ->and($signal->quantity)->toBe('0.001')
                ->and($signal->exitTags)->toBe(['strategy_signal']);
        });

        it('skips exit when no open position', function () {
            $entrySignals = getProtected($this->strategy, 'entrySignals');
            $entrySignals[10] = false;
            setProtected($this->strategy, 'entrySignals', $entrySignals);

            $exitSignals = getProtected($this->strategy, 'exitSignals');
            $exitSignals[10] = true;
            setProtected($this->strategy, 'exitSignals', $exitSignals);

            $portfolio = new PortfolioManager('10000');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toBe([]);
        });

        it('sizes position from total equity by default', function () {
            setProtected($this->strategy, 'positionSizePercent', 2.0);

            $portfolio = new PortfolioManager('10000');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toHaveCount(1);

            $signal = $result[0];
            $expectedSize = (string) (10000.0 * 2.0 / 100.0);
            expect($signal->stakeAmount)->toBe($expectedSize);
        });

        it('uses initial capital for position sizing when method is initial', function () {
            setProtected($this->strategy, 'positionSizingMethod', 'initial');
            setProtected($this->strategy, 'initialCapital', 25000.0);
            setProtected($this->strategy, 'positionSizePercent', 3.0);

            $portfolio = new PortfolioManager('25000');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toHaveCount(1);

            $signal = $result[0];
            $expectedSize = (string) (25000.0 * 3.0 / 100.0);
            expect($signal->stakeAmount)->toBe($expectedSize);
        });

        it('uses equity for sizing when positionSizingMethod is equity', function () {
            setProtected($this->strategy, 'positionSizingMethod', 'equity');
            setProtected($this->strategy, 'positionSizePercent', 2.0);

            $portfolio = new PortfolioManager('5000');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toHaveCount(1);

            $signal = $result[0];
            $expectedSize = (string) (5000.0 * 2.0 / 100.0);
            expect($signal->stakeAmount)->toBe($expectedSize);
        });

        it('uses initial capital for sizing when positionSizingMethod is initial', function () {
            setProtected($this->strategy, 'positionSizingMethod', 'initial');
            setProtected($this->strategy, 'initialCapital', 50000.0);
            setProtected($this->strategy, 'positionSizePercent', 1.0);

            $portfolio = new PortfolioManager('20000');
            $data = makeBarDataMock(currentIndex: 10, portfolio: $portfolio);

            $result = $this->strategy->onBar($data);

            expect($result)->toHaveCount(1);

            $signal = $result[0];
            $expectedSize = (string) (50000.0 * 1.0 / 100.0);
            expect($signal->stakeAmount)->not->toBe((string) (20000.0 * 1.0 / 100.0))
                ->and($signal->stakeAmount)->toBe($expectedSize);
        });
    });

    describe('calculateStopLoss', function () {
        it('computes stop loss from current price and stopLossPercent', function () {
            $ohlcv = makeOhlcvMock(100);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition());
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv));

            $ref = new ReflectionMethod($this->strategy, 'calculateStopLoss');
            $result = $ref->invoke($this->strategy, '100', 0);

            expect($result)->toBe('97.000000');
        });
    });

    describe('calculateTakeProfit', function () {
        it('computes take profit from current price and takeProfitPercent', function () {
            $ohlcv = makeOhlcvMock(100);
            setProtected($this->strategy, 'entryCondition', makeEntryCondition());
            setProtected($this->strategy, 'exitCondition', makeExitCondition());

            $this->strategy->initialize(new InitializeData(ohlcv: $ohlcv));

            $ref = new ReflectionMethod($this->strategy, 'calculateTakeProfit');
            $result = $ref->invoke($this->strategy, '100', 0);

            expect($result)->toBe('106.000000');
        });
    });

    describe('getExitRules', function () {
        it('returns null from DefaultExitRules trait', function () {
            expect($this->strategy->getExitRules())->toBeNull();
        });
    });

    describe('implements StrategyInterface', function () {
        it('implements StrategyInterface through BaseSignalStrategy', function () {
            expect($this->strategy)->toBeInstanceOf(StrategyInterface::class);
            expect($this->strategy)->toBeInstanceOf(BaseSignalStrategy::class);
        });
    });
});
