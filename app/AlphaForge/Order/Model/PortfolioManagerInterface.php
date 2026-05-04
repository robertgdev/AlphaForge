<?php

namespace App\AlphaForge\Order\Model;

use App\AlphaForge\Order\Dto\ExecutionResult;
use App\AlphaForge\Order\Dto\PositionDto;

interface PortfolioManagerInterface
{
    public function initialize(float|string $initialCapital, string $stakeCurrency): void;

    public function getOpenPosition(string $symbol): ?PositionDto;

    public function getAllOpenPositions(): array;

    public function applyExecutionToOpenPosition(ExecutionResult $execution): bool;

    public function applyExecutionToClosePosition(string $positionIdToClose, ExecutionResult $closingExecution): void;

    public function getClosedTrades(): array;

    public function getInitialCapital(): string;

    public function getAvailableCash(): string;
}
