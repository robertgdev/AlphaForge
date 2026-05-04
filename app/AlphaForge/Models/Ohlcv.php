<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ohlcv extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'stochastix_ohlcv';

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
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    /**
     * Get the timeframe that owns this OHLCV record.
     */
    public function timeframe(): BelongsTo
    {
        return $this->belongsTo(Timeframe::class, 'timeframe_id');
    }

    /**
     * Scope a query to filter by timestamp range.
     */
    public function scopeTimestampRange($query, int $start, int $end)
    {
        return $query->whereBetween('timestamp', [$start, $end]);
    }

    /**
     * Scope a query to order by timestamp ascending.
     */
    public function scopeOrderByTimestampAsc($query)
    {
        return $query->orderBy('timestamp', 'asc');
    }

    /**
     * Scope a query to order by timestamp descending.
     */
    public function scopeOrderByTimestampDesc($query)
    {
        return $query->orderBy('timestamp', 'desc');
    }
}
