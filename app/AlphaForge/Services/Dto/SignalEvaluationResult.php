<?php

namespace App\AlphaForge\Services\Dto;

final readonly class SignalEvaluationResult
{
    public function __construct(
        public string $status,
        public ?float $exitPrice = null,
        public ?int $exitTimestamp = null,
        public ?string $exitReason = null,
        public ?float $profitLossPct = null,
        public ?float $profitLossAbs = null,
        public ?string $errorMessage = null,
    ) {}
}
