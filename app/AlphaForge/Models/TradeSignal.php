<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TradeSignal extends Model
{
    use HasUuids;

    protected $table = 'trade_signals';

    protected $fillable = [
        'exchange',
        'symbol',
        'direction',
        'entry_price',
        'stop_loss',
        'take_profit',
        'trailing_stop_enabled',
        'trailing_stop_percent',
        'trailing_stop_high_water_mark',
        'entry_timestamp',
        'status',
        'exit_price',
        'exit_timestamp',
        'exit_reason',
        'profit_loss_pct',
        'profit_loss_abs',
        'last_evaluated_at',
        'timeframe',
        'error_message',
    ];

    protected $casts = [
        'entry_price' => 'decimal:8',
        'stop_loss' => 'decimal:8',
        'take_profit' => 'decimal:8',
        'trailing_stop_enabled' => 'boolean',
        'trailing_stop_percent' => 'decimal:8',
        'trailing_stop_high_water_mark' => 'decimal:8',
        'entry_timestamp' => 'integer',
        'exit_price' => 'decimal:8',
        'exit_timestamp' => 'integer',
        'profit_loss_pct' => 'decimal:8',
        'profit_loss_abs' => 'decimal:8',
        'last_evaluated_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isWinner(): bool
    {
        return $this->status === 'winner';
    }

    public function isLoser(): bool
    {
        return $this->status === 'loser';
    }

    public function markAsWinner(float $exitPrice, int $exitTimestamp, string $exitReason, float $pnlPct, float $pnlAbs): void
    {
        $this->update([
            'status' => 'winner',
            'exit_price' => $exitPrice,
            'exit_timestamp' => $exitTimestamp,
            'exit_reason' => $exitReason,
            'profit_loss_pct' => $pnlPct,
            'profit_loss_abs' => $pnlAbs,
            'last_evaluated_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markAsLoser(float $exitPrice, int $exitTimestamp, string $exitReason, float $pnlPct, float $pnlAbs): void
    {
        $this->update([
            'status' => 'loser',
            'exit_price' => $exitPrice,
            'exit_timestamp' => $exitTimestamp,
            'exit_reason' => $exitReason,
            'profit_loss_pct' => $pnlPct,
            'profit_loss_abs' => $pnlAbs,
            'last_evaluated_at' => now(),
            'error_message' => null,
        ]);
    }

    public function touchEvaluation(): void
    {
        $this->update([
            'last_evaluated_at' => now(),
        ]);
    }

    public function updateWaterMark(float $waterMark): void
    {
        $this->update([
            'trailing_stop_high_water_mark' => $waterMark,
            'last_evaluated_at' => now(),
        ]);
    }
}
