<?php

namespace App\AlphaForge\Backtesting\Model;

final class BacktestCursor
{
    public int $currentIndex = 0;

    public int $executionIndex = 0;

    /**
     * @return array{currentIndex: int, executionIndex: int}
     */
    public function toArray(): array
    {
        return [
            'currentIndex' => $this->currentIndex,
            'executionIndex' => $this->executionIndex,
        ];
    }
}
