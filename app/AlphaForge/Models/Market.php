<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $exchange_id
 * @property string $symbol
 * @property string $base_currency
 * @property string $quote_currency
 * @property bool $active
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 */
class Market extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'alphaforge_markets';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'exchange_id',
        'symbol',
        'base_currency',
        'quote_currency',
        'active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'exchange_id' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the exchange that owns this market.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\AlphaForge\Models\Exchange>
     */
    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class, 'exchange_id');
    }

    /**
     * Get the OHLCV records for this market.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\AlphaForge\Models\Ohlcv>
     */
    public function ohlcvRecords(): HasMany
    {
        return $this->hasMany(Ohlcv::class, 'market_id');
    }

    /**
     * Get the renko records for this market.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\AlphaForge\Models\Renko>
     */
    public function renkoRecords(): HasMany
    {
        return $this->hasMany(Renko::class, 'market_id');
    }

    /**
     * Scope a query to only include active markets.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('active', true);
    }
}
