<?php

namespace App\AlphaForge\Backtesting\Model;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestRun extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'optimization_id',
        'strategy_alias',
        'symbols',
        'timeframe',
        'execution_timeframe',
        'exchange',
        'initial_capital',
        'stake_currency',
        'strategy_inputs',
        'commission_config',
        'start_date',
        'end_date',
        'status',
        'final_capital',
        'statistics',
        'error_message',
        'started_at',
        'completed_at',
        'data_type',
        'brick_size',
        'atr_period',
        'sizing_model',
        'sizing_config',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'symbols' => 'array',
        'strategy_inputs' => 'array',
        'commission_config' => 'array',
        'statistics' => 'array',
        'initial_capital' => 'decimal:8',
        'final_capital' => 'decimal:8',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'brick_size' => 'decimal:8',
        'atr_period' => 'integer',
        'sizing_config' => 'array',
    ];

    /**
     * Get the user that owns this backtest run.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the optimization run that owns this backtest.
     */
    public function optimization(): BelongsTo
    {
        return $this->belongsTo(OptimizationRun::class, 'optimization_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $statistics
     */
    public function markAsCompleted(float $finalCapital, array $statistics): void
    {
        $this->update([
            'status' => 'completed',
            'final_capital' => $finalCapital,
            'statistics' => $statistics,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
