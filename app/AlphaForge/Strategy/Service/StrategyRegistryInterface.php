<?php

namespace App\AlphaForge\Strategy\Service;

use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Dto\StrategyDefinitionDto;
use App\AlphaForge\Strategy\StrategyInterface;

interface StrategyRegistryInterface
{
    /**
     * Check if a strategy exists.
     */
    public function has(string $alias): bool;

    /**
     * Get a strategy instance by alias.
     */
    public function get(string $alias): StrategyInterface;

    /**
     * Get all registered strategies.
     */
    public function all(): array;

    /**
     * Get strategy definition by alias.
     */
    public function getDefinition(string $alias): array;

    /**
     * Get all strategy definitions.
     *
     * @return list<StrategyDefinitionDto>
     */
    public function getStrategyDefinitions(): array;

    /**
     * Get strategy metadata by alias.
     */
    public function getMetadata(string $alias): ?AsStrategy;
}
