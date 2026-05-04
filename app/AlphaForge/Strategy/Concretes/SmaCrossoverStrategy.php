<?php

namespace App\AlphaForge\Strategy\Concretes;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Order\Dto\OrderSignal;
use App\AlphaForge\Order\Enum\OrderTypeEnum;
use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\StrategyInterface;

/**
 * Simple Moving Average Crossover Strategy.
 *
 * This strategy generates buy signals when the fast SMA crosses above the slow SMA,
 * and sell signals when the fast SMA crosses below the slow SMA.
 */
#[AsStrategy(
    alias: 'sma_crossover',
    name: 'SMA Crossover',
    description: 'Simple Moving Average crossover strategy. Buys when fast SMA crosses above slow SMA, sells when it crosses below.',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1]
)]
class SmaCrossoverStrategy implements StrategyInterface
{
    #[Input(
        description: 'Fast SMA period (shorter timeframe)',
        min: 5,
        max: 50,
        step: 5
    )]
    private int $fastPeriod = 10;

    #[Input(
        description: 'Slow SMA period (longer timeframe)',
        min: 20,
        max: 200,
        step: 10
    )]
    private int $slowPeriod = 50;

    #[Input(
        description: 'Stop loss percentage from entry price',
        min: 0.5,
        max: 20.0,
        step: 0.5
    )]
    private float $stopLossPercent = 5.0;

    #[Input(
        description: 'Take profit percentage from entry price',
        min: 1.0,
        max: 50.0,
        step: 2.0
    )]
    private float $takeProfitPercent = 10.0;

    #[Input(
        description: 'Stake amount per trade (in quote currency)',
        min: 10,
        max: 100000,
        step: 1000
    )]
    private string $stakeAmount = '1000';

    /** @var array<int, string> Fast SMA values indexed by bar index */
    private array $fastSma = [];

    /** @var array<int, string> Slow SMA values indexed by bar index */
    private array $slowSma = [];

    /** @var string|null Previous crossover direction */
    private ?string $previousCrossover = null;

    /**
     * Configure the strategy with inputs.
     */
    public function configure(array $inputs): void
    {
        if (isset($inputs['fastPeriod'])) {
            $this->fastPeriod = (int) $inputs['fastPeriod'];
        }
        if (isset($inputs['slowPeriod'])) {
            $this->slowPeriod = (int) $inputs['slowPeriod'];
        }
        if (isset($inputs['stopLossPercent'])) {
            $this->stopLossPercent = (float) $inputs['stopLossPercent'];
        }
        if (isset($inputs['takeProfitPercent'])) {
            $this->takeProfitPercent = (float) $inputs['takeProfitPercent'];
        }
        if (isset($inputs['stakeAmount'])) {
            $this->stakeAmount = (string) $inputs['stakeAmount'];
        }
    }

    /**
     * Called on each bar to generate trading signals.
     */
    public function onBar(array $data): array
    {
        $signals = [];
        $ohlcv = $data['ohlcv'];
        $cursor = $data['cursor'];
        $portfolio = $data['portfolio'];
        $symbol = $data['symbol'];

        $currentIndex = $cursor->currentIndex;
        $closes = $ohlcv->getCloses();

        // Need enough bars for slow SMA
        if ($currentIndex < $this->slowPeriod) {
            return [];
        }

        // Calculate SMAs
        $this->calculateSmas($closes, $currentIndex);

        // Check for crossover
        $crossover = $this->detectCrossover($currentIndex);

        if ($crossover === null) {
            return [];
        }

        // Get current price
        $currentPrice = $closes->getVector()->get($currentIndex);

        // Check if we have an open position
        $openPosition = $portfolio->getOpenPosition($symbol);

        // Generate signals based on crossover
        if ($crossover === 'bullish' && $openPosition === null) {
            // Fast SMA crossed above slow SMA - Buy signal
            $stopLoss = bcmul(
                $currentPrice,
                bcdiv((string) (100 - $this->stopLossPercent), '100', 6),
                6
            );
            $takeProfit = bcmul(
                $currentPrice,
                bcdiv((string) (100 + $this->takeProfitPercent), '100', 6),
                6
            );

            $signals[] = new OrderSignal(
                symbol: $symbol,
                direction: DirectionEnum::LONG,
                orderType: OrderTypeEnum::Market,
                stakeAmount: $this->stakeAmount,
                stopLoss: $stopLoss,
                takeProfit: $takeProfit
            );
        } elseif ($crossover === 'bearish' && $openPosition !== null) {
            // Fast SMA crossed below slow SMA - Sell signal
            $signals[] = new OrderSignal(
                symbol: $symbol,
                direction: DirectionEnum::SHORT,
                orderType: OrderTypeEnum::Market,
                quantity: $openPosition->quantity
            );
        }

        $this->previousCrossover = $crossover;

        return $signals;
    }

    /**
     * Calculate SMAs for the current bar.
     */
    private function calculateSmas($closes, int $currentIndex): void
    {
        $closesVec = $closes->getVector();
        
        // Calculate fast SMA
        $fastSum = '0';
        for ($i = $currentIndex - $this->fastPeriod + 1; $i <= $currentIndex; $i++) {
            $fastSum = bcadd($fastSum, $closesVec->get($i), 12);
        }
        $this->fastSma[$currentIndex] = bcdiv($fastSum, (string) $this->fastPeriod, 12);

        // Calculate slow SMA
        $slowSum = '0';
        for ($i = $currentIndex - $this->slowPeriod + 1; $i <= $currentIndex; $i++) {
            $slowSum = bcadd($slowSum, $closesVec->get($i), 12);
        }
        $this->slowSma[$currentIndex] = bcdiv($slowSum, (string) $this->slowPeriod, 12);
    }

    /**
     * Detect SMA crossover.
     */
    private function detectCrossover(int $currentIndex): ?string
    {
        $prevIndex = $currentIndex - 1;

        if (! isset($this->fastSma[$prevIndex]) || ! isset($this->slowSma[$prevIndex])) {
            return null;
        }

        $prevFast = $this->fastSma[$prevIndex];
        $prevSlow = $this->slowSma[$prevIndex];
        $currFast = $this->fastSma[$currentIndex];
        $currSlow = $this->slowSma[$currentIndex];

        // Bullish crossover: fast crosses above slow
        if (bccomp($prevFast, $prevSlow, 12) <= 0 && bccomp($currFast, $currSlow, 12) > 0) {
            return 'bullish';
        }

        // Bearish crossover: fast crosses below slow
        if (bccomp($prevFast, $prevSlow, 12) >= 0 && bccomp($currFast, $currSlow, 12) < 0) {
            return 'bearish';
        }

        return null;
    }
}
