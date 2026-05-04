<?php

namespace App\AlphaForge\Order\Service;

use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Order\Dto\ExecutionResult;
use App\AlphaForge\Order\Dto\OrderSignal;

interface OrderExecutorInterface
{
    /**
     * Execute an order signal.
     */
    public function execute(OrderSignal $signal, OhlcvSeries $executionBarData, \DateTimeImmutable $executionTime): ?ExecutionResult;
}
