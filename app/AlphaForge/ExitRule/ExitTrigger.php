<?php

namespace App\AlphaForge\ExitRule;

final readonly class ExitTrigger
{
    public function __construct(
        public string $ruleId,
        public float $exitPrice,
        public ?string $exitTag = null,
    ) {}
}
