<?php

namespace App\AlphaForge\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Http\JsonResponse;

class StrategyController extends Controller
{
    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry
    ) {}

    /**
     * List all available strategies.
     */
    public function index(): JsonResponse
    {
        $strategies = $this->strategyRegistry->all();

        $data = [];
        foreach ($strategies as $alias => $strategy) {
            $definition = $this->strategyRegistry->getDefinition($alias);

            $data[] = [
                'alias' => $alias,
                'name' => $definition['name'] ?? $alias,
                'description' => $definition['description'] ?? null,
                'inputs' => $definition['inputs'] ?? [],
                'timeframes' => $definition['timeframes'] ?? [],
            ];
        }

        return response()->json([
            'strategies' => $data,
        ]);
    }

    /**
     * Get details for a specific strategy.
     */
    public function show(string $alias): JsonResponse
    {
        if (! $this->strategyRegistry->has($alias)) {
            return response()->json([
                'message' => 'Strategy not found',
            ], 404);
        }

        $definition = $this->strategyRegistry->getDefinition($alias);

        return response()->json([
            'alias' => $alias,
            'name' => $definition['name'] ?? $alias,
            'description' => $definition['description'] ?? null,
            'inputs' => $definition['inputs'] ?? [],
            'timeframes' => $definition['timeframes'] ?? [],
            'class' => get_class($this->strategyRegistry->get($alias)),
        ]);
    }
}
