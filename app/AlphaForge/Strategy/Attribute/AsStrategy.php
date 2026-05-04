<?php

namespace App\AlphaForge\Strategy\Attribute;

use App\AlphaForge\Common\Enum\TimeframeEnum;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsStrategy
{
    /**
     * @param  TimeframeEnum[]  $requiredMarketData
     */
    public function __construct(
        public string $alias,
        public string $name,
        public ?string $description = null,
        public ?TimeframeEnum $timeframe = null,
        public array $requiredMarketData = [],
    ) {}
}
