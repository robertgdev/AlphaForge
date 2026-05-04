<?php

namespace App\Http\Controllers\Stochastix\Data;

use App\Http\Controllers\Controller;
use App\AlphaForge\Data\Exception\ExchangeException;
use App\AlphaForge\Data\Service\MarketDataService;
use Illuminate\Http\JsonResponse;

class SymbolsController extends Controller
{
    public function __construct(
        private readonly MarketDataService $marketDataService
    ) {}

    /**
     * Get futures/swap symbols for a specific exchange.
     */
    public function index(string $exchangeId): JsonResponse
    {
        try {
            $symbols = $this->marketDataService->getFuturesSymbols($exchangeId);

            return response()->json($symbols);
        } catch (ExchangeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
