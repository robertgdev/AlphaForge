<?php

namespace App\Http\Controllers\AlphaForge\Data;

use App\AlphaForge\Data\Exception\DataFileNotFoundException;
use App\AlphaForge\Data\Service\DataInspectionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class InspectController extends Controller
{
    public function __construct(
        private readonly DataInspectionService $inspectionService
    ) {}

    /**
     * Inspect a stored market data file.
     */
    public function show(string $exchangeId, string $symbol, string $timeframe): JsonResponse
    {
        try {
            // The symbol in the URL might have a different separator, e.g. '-', so we replace it with '/'.
            $formattedSymbol = str_replace('-', '/', $symbol);

            $inspectionResult = $this->inspectionService->inspect($exchangeId, $formattedSymbol, $timeframe);

            return response()->json($inspectionResult);
        } catch (DataFileNotFoundException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            // For security, don't expose internal error details in production
            return response()->json(['error' => 'An unexpected error occurred during data inspection.'], 500);
        }
    }
}
