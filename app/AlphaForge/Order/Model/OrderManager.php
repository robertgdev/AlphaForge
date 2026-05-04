<?php

namespace App\AlphaForge\Order\Model;

use App\AlphaForge\Order\Dto\PendingOrder;
use Ds\Map;

final class OrderManager
{
    /** @var Map<string, PendingOrder> Keyed by order ID */
    private Map $pendingOrders;

    public function __construct()
    {
        $this->pendingOrders = new Map;
    }

    /**
     * Add a pending order.
     */
    public function addPendingOrder(PendingOrder $order): void
    {
        $this->pendingOrders->put($order->id, $order);
    }

    /**
     * Remove a pending order.
     */
    public function removePendingOrder(string $orderId): void
    {
        if ($this->pendingOrders->hasKey($orderId)) {
            $this->pendingOrders->remove($orderId);
        }
    }

    /**
     * Get all pending orders.
     *
     * @return iterable<PendingOrder>
     */
    public function getPendingOrders(): iterable
    {
        return $this->pendingOrders->values();
    }

    /**
     * Check if there are any pending orders.
     */
    public function hasPendingOrders(): bool
    {
        return ! $this->pendingOrders->isEmpty();
    }

    /**
     * Get a pending order by ID.
     */
    public function getPendingOrder(string $orderId): ?PendingOrder
    {
        if (! $this->pendingOrders->hasKey($orderId)) {
            return null;
        }

        return $this->pendingOrders->get($orderId);
    }

    /**
     * Clear all pending orders.
     */
    public function clearPendingOrders(): void
    {
        $this->pendingOrders->clear();
    }
}
