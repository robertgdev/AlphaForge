<?php

namespace App\AlphaForge\Backtesting\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $walk_forward_run_id
 * @property int $rank
 * @property array<string, mixed> $parameters
 * @property string|null $is_final_capital
 * @property array<string, mixed>|null $is_statistics
 * @property float|null $is_score
 * @property string|null $oos_final_capital
 * @property array<string, mixed>|null $oos_statistics
 * @property float|null $oos_score
 * @property float|null $score_degradation
 */
class WalkForwardResult extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'walk_forward_run_id',
        'rank',
        'parameters',
        'is_final_capital',
        'is_statistics',
        'is_score',
        'oos_final_capital',
        'oos_statistics',
        'oos_score',
        'score_degradation',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_final_capital' => 'decimal:8',
        'is_statistics' => 'array',
        'is_score' => 'float',
        'oos_final_capital' => 'decimal:8',
        'oos_statistics' => 'array',
        'oos_score' => 'float',
        'score_degradation' => 'float',
    ];

    public function walkForwardRun(): BelongsTo
    {
        return $this->belongsTo(WalkForwardRun::class);
    }
}
