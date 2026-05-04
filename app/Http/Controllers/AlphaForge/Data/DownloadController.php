<?php

namespace App\Http\Controllers\Stochastix\Data;

use App\Http\Controllers\Controller;
use App\AlphaForge\Jobs\DownloadMarketDataJob;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DownloadController extends Controller
{
    /**
     * Launch a market data download job.
     */
    public function launch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exchangeId' => 'required|string',
            'symbol' => 'required|string',
            'timeframe' => 'required|string',
            'startDate' => 'required|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
            'forceOverwrite' => 'boolean',
        ]);

        try {
            $jobId = uniqid('download_', true);

            $job = new DownloadMarketDataJob(
                exchangeId: $validated['exchangeId'],
                symbol: $validated['symbol'],
                timeframe: $validated['timeframe'],
                startDate: Carbon::parse($validated['startDate']),
                endDate: isset($validated['endDate']) ? Carbon::parse($validated['endDate']) : Carbon::now(),
                forceOverwrite: $validated['forceOverwrite'] ?? false,
                jobId: $jobId
            );

            dispatch($job);

            Log::info('Data download has been queued.', [
                'jobId' => $jobId,
                'exchange' => $validated['exchangeId'],
                'symbol' => $validated['symbol'],
            ]);

            return response()->json([
                'status' => 'queued',
                'jobId' => $jobId,
            ], 202);
        } catch (\Throwable $e) {
            Log::error('Failed to queue data download: {message}', ['message' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to queue data download process.'], 500);
        }
    }

    /**
     * Cancel a running download job.
     */
    public function cancel(string $jobId): JsonResponse
    {
        // Set a cancellation flag in cache that the job checks periodically
        $cacheKey = "stochastix.download.cancel.{$jobId}";
        cache()->put($cacheKey, true, now()->addHour());

        return response()->json([
            'status' => 'cancellation_requested',
            'jobId' => $jobId,
        ], 202);
    }
}
