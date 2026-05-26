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
 * @property string|null $optimization_run_id
 * @property string $strategy_alias
 * @property array<string> $symbols
 * @property string $timeframe
 * @property string $exchange
 * @property string $initial_capital
 * @property string|null $stake_currency
 * @property array<string, mixed>|null $commission_config
 * @property \DateTimeInterface|null $is_start_date
 * @property \DateTimeInterface|null $is_end_date
 * @property \DateTimeInterface|null $oos_start_date
 * @property \DateTimeInterface|null $oos_end_date
 * @property float $split_ratio
 * @property string $optimization_method
 * @property string|null $optimization_objective
 * @property int $top_n
 * @property array<string, mixed>|null $parameter_ranges
 * @property int|null $total_combinations
 * @property int $completed_combinations
 * @property string $status
 * @property string|null $error_message
 * @property array<string, mixed>|null $best_parameters
 * @property array<string, mixed>|null $best_is_statistics
 * @property array<string, mixed>|null $best_oos_statistics
 * @property \DateTimeInterface|null $started_at
 * @property \DateTimeInterface|null $completed_at
 * @property string|null $data_type
 * @property float|null $brick_size
 * @property int|null $atr_period
 */
class WalkForwardRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'optimization_run_id',
        'strategy_alias',
        'symbols',
        'timeframe',
        'exchange',
        'initial_capital',
        'stake_currency',
        'commission_config',
        'is_start_date',
        'is_end_date',
        'oos_start_date',
        'oos_end_date',
        'split_ratio',
        'optimization_method',
        'optimization_objective',
        'top_n',
        'parameter_ranges',
        'total_combinations',
        'completed_combinations',
        'execution_timeframe',
        'min_trades_threshold',
        'data_type',
        'brick_size',
        'atr_period',
        'status',
        'error_message',
        'best_parameters',
        'best_is_statistics',
        'best_oos_statistics',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'symbols' => 'array',
        'commission_config' => 'array',
        'parameter_ranges' => 'array',
        'best_parameters' => 'array',
        'best_is_statistics' => 'array',
        'best_oos_statistics' => 'array',
        'initial_capital' => 'decimal:8',
        'split_ratio' => 'float',
        'top_n' => 'integer',
        'total_combinations' => 'integer',
        'completed_combinations' => 'integer',
        'min_trades_threshold' => 'integer',
        'data_type' => 'string',
        'brick_size' => 'decimal:8',
        'atr_period' => 'integer',
        'data_type' => 'string',
        'brick_size' => 'decimal:8',
        'atr_period' => 'integer',
        'is_start_date' => 'datetime',
        'is_end_date' => 'datetime',
        'oos_start_date' => 'datetime',
        'oos_end_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function optimizationRun(): BelongsTo
    {
        return $this->belongsTo(OptimizationRun::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(WalkForwardResult::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isOptimizing(): bool
    {
        return $this->status === 'optimizing';
    }

    public function isForwardTesting(): bool
    {
        return $this->status === 'forward_testing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsOptimizing(): void
    {
        $this->update([
            'status' => 'optimizing',
            'started_at' => now(),
        ]);
    }

    public function markAsForwardTesting(): void
    {
        $this->update(['status' => 'forward_testing']);
    }

    /**
     * @param  array<string, mixed>  $bestParameters
     * @param  array<string, mixed>  $bestIsStatistics
     * @param  array<string, mixed>  $bestOosStatistics
     */
    public function markAsCompleted(array $bestParameters, array $bestIsStatistics, array $bestOosStatistics): void
    {
        $this->update([
            'status' => 'completed',
            'best_parameters' => $bestParameters,
            'best_is_statistics' => $bestIsStatistics,
            'best_oos_statistics' => $bestOosStatistics,
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
