<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $market_id
 * @property int $timeframe_id
 * @property int $timestamp
 * @property string $open
 * @property string $high
 * @property string $low
 * @property string $close
 * @property string $volume
 */
class Ohlcv extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'alphaforge_ohlcv';

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
        'timestamp',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'market_id' => 'integer',
        'timeframe_id' => 'integer',
        'timestamp' => 'integer',
        'open' => 'decimal:12',
        'high' => 'decimal:12',
        'low' => 'decimal:12',
        'close' => 'decimal:12',
        'volume' => 'decimal:12',
    ];

    /**
     * Get the market that owns this OHLCV record.
     *
     * @return BelongsTo<Market>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    /**
     * Get the timeframe that owns this OHLCV record.
     *
     * @return BelongsTo<Timeframe>
     */
    public function timeframe(): BelongsTo
    {
        return $this->belongsTo(Timeframe::class, 'timeframe_id');
    }

    /**
     * Scope a query to filter by timestamp range.
     *
     * @param  Builder<static>  $query
     */
    public function scopeTimestampRange($query, int $start, int $end): Builder
    {
        return $query->whereBetween('timestamp', [$start, $end]);
    }

    /**
     * Scope a query to order by timestamp ascending.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderByTimestampAsc($query): Builder
    {
        return $query->orderBy('timestamp', 'asc');
    }

    /**
     * Scope a query to order by timestamp descending.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderByTimestampDesc($query): Builder
    {
        return $query->orderBy('timestamp', 'desc');
    }
}
