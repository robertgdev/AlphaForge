<?php

namespace App\Http\Controllers\AlphaForge\Data;

use App\Http\Controllers\Controller;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use Illuminate\Http\JsonResponse;

class DataAvailabilityController extends Controller
{
    public function __construct(
        private readonly DataAvailabilityService $dataAvailabilityService
    ) {}

    /**
     * Get a manifest of all available market data.
     */
    public function index(): JsonResponse
    {
        $manifest = $this->dataAvailabilityService->getManifest();

        return response()->json($manifest);
    }
}
