<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $market_id
 * @property int $timeframe_id
 * @property int $renko_type_id
 * @property int $timestamp
 * @property string $open
 * @property string $close
 * @property string $direction
 */
class Renko extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'alphaforge_renko';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'timeframe_id',
        'renko_type_id',
        'timestamp',
        'open',
        'close',
        'direction',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'market_id' => 'integer',
        'timeframe_id' => 'integer',
        'renko_type_id' => 'integer',
        'timestamp' => 'integer',
        'open' => 'decimal:12',
        'close' => 'decimal:12',
        'direction' => 'string',
    ];

    /**
     * Get the market that owns this renko record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AlphaForge\Models\Market>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    /**
     * Get the timeframe that owns this renko record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AlphaForge\Models\Timeframe>
     */
    public function timeframe(): BelongsTo
    {
        return $this->belongsTo(Timeframe::class, 'timeframe_id');
    }

    /**
     * Get the renko type that owns this renko record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AlphaForge\Models\RenkoType>
     */
    public function renkoType(): BelongsTo
    {
        return $this->belongsTo(RenkoType::class, 'renko_type_id');
    }

    /**
     * Scope a query to filter by timestamp range.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeTimestampRange($query, int $start, int $end): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereBetween('timestamp', [$start, $end]);
    }

    /**
     * Scope a query to filter by direction.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeByDirection($query, string $direction): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('direction', $direction);
    }

    /**
     * Scope a query to only include up bricks.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeUp($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('direction', 'up');
    }

    /**
     * Scope a query to only include down bricks.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDown($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('direction', 'down');
    }

    /**
     * Scope a query to order by timestamp ascending.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOrderByTimestampAsc($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->orderBy('timestamp', 'asc');
    }

    /**
     * Scope a query to order by timestamp descending.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOrderByTimestampDesc($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->orderBy('timestamp', 'desc');
    }
}
