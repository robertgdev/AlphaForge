<?php

namespace App\AlphaForge\Backtesting\Model;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string $strategy_alias
 * @property array $symbols
 * @property string $timeframe
 * @property string $exchange
 * @property string $initial_capital
 * @property string|null $stake_currency
 * @property array|null $commission_config
 * @property string|null $start_date
 * @property string|null $end_date
 * @property array|null $parameter_ranges
 * @property string $optimization_metric
 * @property int|null $total_combinations
 * @property int|null $completed_combinations
 * @property string $status
 * @property array|null $best_parameters
 * @property array|null $best_statistics
 * @property string|null $error_message
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $completed_at
 */
class OptimizationRun extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
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

    /**
     * @var array<string, string>
     */
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

    /**
     * Get the user that owns this optimization run.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the backtest runs for this optimization.
     */
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

    /**
     * @param array<string, mixed> $bestParameters
     * @param array<string, mixed> $bestStatistics
     */
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
