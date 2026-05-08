<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     */
    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class, 'exchange_id');
    }

    /**
     * Get the OHLCV records for this market.
     */
    public function ohlcvRecords(): HasMany
    {
        return $this->hasMany(Ohlcv::class, 'market_id');
    }

    /**
     * Get the renko records for this market.
     */
    public function renkoRecords(): HasMany
    {
        return $this->hasMany(Renko::class, 'market_id');
    }

    /**
     * Scope a query to only include active markets.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
