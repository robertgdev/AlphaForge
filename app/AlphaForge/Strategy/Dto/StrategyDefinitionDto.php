<?php

namespace App\AlphaForge\Strategy\Dto;

final readonly class StrategyDefinitionDto
{
    /**
     * @param  InputDefinitionDto[]  $inputs
     * @param  string[]  $requiredMarketData
     */
    public function __construct(
        public string $alias,
        public string $name,
        public ?string $description,
        public array $inputs,
        public ?string $timeframe = null,
        public array $requiredMarketData = []
    ) {}
}
