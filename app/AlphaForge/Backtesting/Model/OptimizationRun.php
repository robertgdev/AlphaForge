<?php

namespace App\AlphaForge\Backtesting\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OptimizationRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'strategy_alias',
        'symbols',
        'timeframe',
        'exchange',
        'initial_capital',
        'stake_currency',
        'commission_config',
        'start_date',
        'end_date',
        'parameter_ranges',
        'optimization_metric',
        'total_combinations',
        'completed_combinations',
        'status',
        'best_parameters',
        'best_statistics',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'symbols' => 'array',
        'commission_config' => 'array',
        'parameter_ranges' => 'array',
        'best_parameters' => 'array',
        'best_statistics' => 'array',
        'initial_capital' => 'decimal:8',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backtestRuns(): HasMany
    {
        return $this->hasMany(BacktestRun::class);
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

    public function markAsCompleted(array $bestParameters, array $bestStatistics): void
    {
        $this->update([
            'status' => 'completed',
            'best_parameters' => $bestParameters,
            'best_statistics' => $bestStatistics,
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

    public function incrementProgress(): void
    {
        $this->increment('completed_combinations');
    }

    public function getProgressPercent(): float
    {
        if ($this->total_combinations === 0) {
            return 0.0;
        }
        return ($this->completed_combinations / $this->total_combinations) * 100;
    }
}
