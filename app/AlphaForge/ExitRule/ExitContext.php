<?php

namespace App\AlphaForge\ExitRule;

use App\AlphaForge\Order\Dto\PositionDto;

final readonly class ExitContext
{
    public function __construct(
        public PositionDto $position,
        public int $barIndex,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
        public int $timestamp,
        public int $barsInPosition,
        public float $highestSinceEntry,
        public float $lowestSinceEntry,
    ) {}
}
