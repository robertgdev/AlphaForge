<?php

namespace App\AlphaForge\Http\Controllers\Api;

use App\AlphaForge\Http\Requests\SubmitTradeSignalRequest;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\TradeSignalEvaluator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeSignalController extends Controller
{
    public function __construct(
        private readonly TradeSignalEvaluator $evaluator
    ) {}

    public function store(SubmitTradeSignalRequest $request): JsonResponse
    {
        $signal = new TradeSignal([
            'exchange' => $request->validated('exchange'),
            'symbol' => strtoupper($request->validated('symbol')),
            'direction' => strtoupper($request->validated('direction')),
            'entry_price' => $request->validated('entry_price'),
            'stop_loss' => $request->validated('stop_loss'),
            'take_profit' => $request->validated('take_profit'),
            'trailing_stop_enabled' => $request->boolean('trailing_stop_enabled'),
            'trailing_stop_percent' => $request->validated('trailing_stop_percent'),
            'entry_timestamp' => $request->validated('entry_timestamp', now()->timestamp),
            'timeframe' => $request->validated('timeframe'),
            'status' => 'open',
        ]);

        $result = $this->evaluator->evaluate($signal);
        $signal->status = $result->status;

        if ($result->exitPrice !== null) {
            $signal->exit_price = $result->exitPrice;
            $signal->exit_timestamp = $result->exitTimestamp;
            $signal->exit_reason = $result->exitReason;
            $signal->profit_loss_pct = $result->profitLossPct;
            $signal->profit_loss_abs = $result->profitLossAbs;
        }

        $signal->save();

        return response()->json([
            'message' => 'Trade signal evaluated successfully.',
            'trade_signal_id' => $signal->id,
            'status' => $signal->status,
        ], 202);
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $signal = TradeSignal::findOrFail($id);

        return response()->json([
            'id' => $signal->id,
            'exchange' => $signal->exchange,
            'symbol' => $signal->symbol,
            'direction' => $signal->direction,
            'entry_price' => (float) $signal->entry_price,
            'stop_loss' => (float) $signal->stop_loss,
            'take_profit' => (float) $signal->take_profit,
            'trailing_stop_enabled' => $signal->trailing_stop_enabled,
            'trailing_stop_percent' => $signal->trailing_stop_percent !== null
                ? (float) $signal->trailing_stop_percent
                : null,
            'trailing_stop_high_water_mark' => $signal->trailing_stop_high_water_mark !== null
                ? (float) $signal->trailing_stop_high_water_mark
                : null,
            'entry_timestamp' => $signal->entry_timestamp,
            'timeframe' => $signal->timeframe,
            'status' => $signal->status,
            'exit_price' => $signal->exit_price !== null ? (float) $signal->exit_price : null,
            'exit_timestamp' => $signal->exit_timestamp,
            'exit_reason' => $signal->exit_reason,
            'profit_loss_pct' => $signal->profit_loss_pct !== null
                ? (float) $signal->profit_loss_pct
                : null,
            'profit_loss_abs' => $signal->profit_loss_abs !== null
                ? (float) $signal->profit_loss_abs
                : null,
            'error_message' => $signal->error_message,
            'last_evaluated_at' => $signal->last_evaluated_at?->toIso8601String(),
            'created_at' => $signal->created_at?->toIso8601String(),
            'updated_at' => $signal->updated_at?->toIso8601String(),
        ]);
    }
}