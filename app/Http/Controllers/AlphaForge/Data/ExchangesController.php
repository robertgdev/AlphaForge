<?php

namespace App\Http\Controllers\AlphaForge\Data;

use App\Http\Controllers\Controller;
use App\AlphaForge\Data\Service\MarketDataService;
use Illuminate\Http\JsonResponse;

class ExchangesController extends Controller
{
    public function __construct(
        private readonly MarketDataService $marketDataService
    ) {}

    /**
     * Get a list of all supported exchanges.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->marketDataService->getExchanges());
    }
}
