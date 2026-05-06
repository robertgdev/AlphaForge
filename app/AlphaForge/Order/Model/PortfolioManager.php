<?php

namespace App\AlphaForge\Order\Model;

use App\AlphaForge\Common\Enum\DirectionEnum;
use App\AlphaForge\Order\Dto\ExecutionResult;
use App\AlphaForge\Order\Dto\PendingOrder;
use App\AlphaForge\Order\Dto\PositionDto;
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;

final class PortfolioManager
{
    /** @var Map<string, PositionDto> Open positions keyed by symbol */
    private Map $openPositions;

    /** @var Vector<PositionDto> All closed positions */
    private Vector $closedPositions;

    private string $cashBalance;

    private readonly string $initialCapital;

    public function __construct(string $initialCapital)
    {
        $this->initialCapital = $initialCapital;
        $this->cashBalance = $initialCapital;
        $this->openPositions = new Map;
        $this->closedPositions = new Vector;
    }

    /**
     * Get the current cash balance.
     */
    public function getCashBalance(): string
    {
        return $this->cashBalance;
    }

    /**
     * Get the initial capital.
     */
    public function getInitialCapital(): string
    {
        return $this->initialCapital;
    }

    /**
     * Get available cash for trading.
     */
    public function getAvailableCash(): string
    {
        return $this->cashBalance;
    }

    /**
     * Get all open positions.
     *
     * @return iterable<PositionDto>
     */
    public function getOpenPositions(): iterable
    {
        return $this->openPositions->values();
    }

    /**
     * Get an open position by symbol.
     */
    public function getOpenPosition(string $symbol): ?PositionDto
    {
        if (!$this->openPositions->hasKey($symbol)) {
            return null;
        }
        return $this->openPositions->get($symbol);
    }

    /**
     * Get an open position by ID.
     */
    public function getOpenPositionById(string $positionId): ?PositionDto
    {
        foreach ($this->openPositions as $position) {
            if ($position->id === $positionId) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Execute an order and update the portfolio.
     */
    public function executeOrder(
        PendingOrder $order,
        string $executionPrice,
        Carbon $executionTime,
        array $commissionConfig = []
    ): ExecutionResult {
        // Calculate quantity based on stake amount and price
        $quantity = bcdiv($order->stakeAmount, $executionPrice, 12);

        // Calculate commission
        $commission = $this->calculateCommission(
            $order->stakeAmount,
            $commissionConfig
        );

        if ($order->direction === DirectionEnum::LONG) {
            // Deduct from cash balance
            $totalCost = bcadd($order->stakeAmount, $commission, 12);
            $this->cashBalance = bcsub($this->cashBalance, $totalCost, 12);

            // Create new position
            $position = new PositionDto(
                id: uniqid('pos_', true),
                symbol: $order->symbol,
                direction: 'long',
                quantity: $quantity,
                entryPrice: $executionPrice,
                entryTime: $executionTime,
                stopLoss: $order->stopLoss,
                takeProfit: $order->takeProfit,
                costBasis: $order->stakeAmount,
                commission: $commission,
            );

            $this->openPositions->put($order->symbol, $position);

            return new ExecutionResult(
                orderId: $order->id,
                symbol: $order->symbol,
                direction: $order->direction,
                quantity: $quantity,
                price: $executionPrice,
                commission: $commission,
                timestamp: $executionTime,
                position: $position,
            );
        } else {
            // Short position
            $position = $this->openPositions->hasKey($order->symbol)
                ? $this->openPositions->get($order->symbol)
                : null;

            if ($position) {
                // Close existing long position and return execution result
                $closedPosition = $this->closePosition($position->id, $executionPrice, $executionTime, $commissionConfig);
                if ($closedPosition) {
                    return new ExecutionResult(
                        orderId: $order->id,
                        symbol: $order->symbol,
                        direction: $order->direction,
                        quantity: $closedPosition->quantity,
                        price: $executionPrice,
                        commission: $closedPosition->commission,
                        timestamp: $executionTime,
                        position: $closedPosition,
                    );
                }
                return null;
            }

            // Create new short position (simplified - would need margin calculation)
            $totalCost = bcadd($order->stakeAmount, $commission, 12);
            $this->cashBalance = bcsub($this->cashBalance, $totalCost, 12);

            $position = new PositionDto(
                id: uniqid('pos_', true),
                symbol: $order->symbol,
                direction: 'short',
                quantity: $quantity,
                entryPrice: $executionPrice,
                entryTime: $executionTime,
                stopLoss: $order->stopLoss,
                takeProfit: $order->takeProfit,
                costBasis: $order->stakeAmount,
                commission: $commission,
            );

            $this->openPositions->put($order->symbol, $position);

            return new ExecutionResult(
                orderId: $order->id,
                symbol: $order->symbol,
                direction: $order->direction,
                quantity: $quantity,
                price: $executionPrice,
                commission: $commission,
                timestamp: $executionTime,
                position: $position,
            );
        }
    }

    /**
     * Close a position.
     */
    public function closePosition(
        string $positionId,
        string $exitPrice,
        Carbon $exitTime,
        array $commissionConfig = []
    ): ?PositionDto {
        $position = $this->getOpenPositionById($positionId);

        if (! $position) {
            return null;
        }

        // Calculate exit value
        $exitValue = bcmul($position->quantity, $exitPrice, 12);

        // Calculate commission on exit
        $exitCommission = $this->calculateCommission($exitValue, $commissionConfig);

        // Calculate realized PnL
        $realizedPnl = $this->calculateRealizedPnl(
            $position,
            $exitPrice,
            $exitCommission
        );

        // Update cash balance
        if ($position->direction === 'long') {
            $this->cashBalance = bcadd($this->cashBalance, bcsub($exitValue, $exitCommission, 12), 12);
        } else {
            // For shorts, add the profit/loss
            $this->cashBalance = bcadd($this->cashBalance, bcsub($exitValue, $exitCommission, 12), 12);
        }

        // Create closed position DTO
        $closedPosition = new PositionDto(
            id: $position->id,
            symbol: $position->symbol,
            direction: $position->direction,
            quantity: $position->quantity,
            entryPrice: $position->entryPrice,
            entryTime: $position->entryTime,
            exitPrice: $exitPrice,
            exitTime: $exitTime,
            realizedPnl: $realizedPnl,
            stopLoss: $position->stopLoss,
            takeProfit: $position->takeProfit,
            costBasis: $position->costBasis,
            commission: bcadd($position->commission, $exitCommission, 12),
        );

        // Remove from open positions
        $this->openPositions->remove($position->symbol);

        // Add to closed positions
        $this->closedPositions->push($closedPosition);

        return $closedPosition;
    }

    /**
     * Calculate realized PnL for a closing position.
     */
    private function calculateRealizedPnl(
        PositionDto $position,
        string $exitPrice,
        string $exitCommission
    ): string {
        $entryValue = bcmul($position->quantity, $position->entryPrice, 12);
        $exitValue = bcmul($position->quantity, $exitPrice, 12);

        if ($position->direction === 'long') {
            $pnl = bcsub($exitValue, $entryValue, 12);
        } else {
            $pnl = bcsub($entryValue, $exitValue, 12);
        }

        // Subtract total commissions
        $totalCommission = bcadd($position->commission, $exitCommission, 12);

        return bcsub($pnl, $totalCommission, 12);
    }

    /**
     * Calculate commission for a trade.
     */
    private function calculateCommission(string $tradeValue, array $config): string
    {
        if (empty($config)) {
            return '0';
        }

        $type = $config['type'] ?? 'percentage';
        $rate = $config['rate'] ?? '0';
        $minimum = $config['minimum'] ?? '0';

        if ($type === 'percentage') {
            $commission = bcmul($tradeValue, bcdiv($rate, '100', 12), 12);
        } else {
            // Fixed fee
            $commission = $rate;
        }

        // Apply minimum
        if (bccomp($minimum, $commission, 12) > 0) {
            $commission = $minimum;
        }

        return $commission;
    }

    /**
     * Get the default stake amount (10% of available cash).
     */
    public function getDefaultStakeAmount(): string
    {
        return bcdiv($this->cashBalance, '10', 12);
    }

    /**
     * Get total equity (cash + open positions value).
     */
    public function getTotalEquity(array $currentPrices = []): string
    {
        $equity = $this->cashBalance;

        foreach ($this->openPositions as $symbol => $position) {
            $price = $currentPrices[$symbol] ?? $position->entryPrice;
            $positionValue = bcmul($position->quantity, $price, 12);
            $equity = bcadd($equity, $positionValue, 12);
        }

        return $equity;
    }

    /**
     * Get all closed positions.
     *
     * @return Vector<PositionDto>
     */
    public function getClosedPositions(): Vector
    {
        return $this->closedPositions;
    }
}
