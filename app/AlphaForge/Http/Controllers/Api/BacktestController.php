<?php

namespace App\AlphaForge\Http\Controllers\Api;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use App\AlphaForge\Http\Requests\RunBacktestRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BacktestController extends Controller
{
    public function __construct(
        private readonly BacktestRunService $backtestRunService
    ) {}

    /**
     * List all backtest runs for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BacktestRun::query()
            ->when($request->user(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderBy('created_at', 'desc');

        $backtests = $query->paginate($request->input('per_page', 15));

        return response()->json($backtests);
    }

    /**
     * Get a specific backtest run.
     */
    public function show(string $id, Request $request): JsonResponse
    {
        $backtest = BacktestRun::query()
            ->when($request->user(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        return response()->json($backtest);
    }

    /**
     * Queue a new backtest run.
     */
    public function store(RunBacktestRequest $request): JsonResponse
    {
        $backtestRun = $this->backtestRunService->queue([
            'user_id' => $request->user()?->id,
            'strategy' => $request->validated('strategy'),
            'symbols' => $request->validated('symbols'),
            'timeframe' => $request->validated('timeframe'),
            'execution_timeframe' => $request->validated('execution_timeframe'),
            'exchange' => $request->validated('exchange'),
            'initial_capital' => $request->validated('initial_capital'),
            'stake_currency' => $request->validated('stake_currency', 'USDT'),
            'strategy_inputs' => $request->validated('strategy_inputs', []),
            'commission_config' => $request->validated('commission_config', []),
            'start_date' => $request->validated('start_date'),
            'end_date' => $request->validated('end_date'),
        ]);

        return response()->json([
            'message' => 'Backtest queued successfully',
            'backtest_id' => $backtestRun->id,
        ], 202);
    }

    /**
     * Cancel a pending backtest.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $backtest = BacktestRun::query()
            ->when($request->user(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        if (! $backtest->isPending()) {
            return response()->json([
                'message' => 'Cannot cancel a backtest that is not pending',
            ], 400);
        }

        $backtest->update(['status' => 'failed', 'error_message' => 'Cancelled by user']);

        return response()->json([
            'message' => 'Backtest cancelled',
        ]);
    }

    /**
     * Get the statistics for a completed backtest.
     */
    public function statistics(string $id, Request $request): JsonResponse
    {
        $backtest = BacktestRun::query()
            ->when($request->user(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        if (! $backtest->isCompleted()) {
            return response()->json([
                'message' => 'Backtest is not completed',
                'status' => $backtest->status,
            ], 400);
        }

        return response()->json([
            'statistics' => $backtest->statistics,
        ]);
    }
}
