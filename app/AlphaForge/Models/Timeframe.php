<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timeframe extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'stochastix_timeframes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'minutes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'minutes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the OHLCV records for this timeframe.
     */
    public function ohlcvRecords(): HasMany
    {
        return $this->hasMany(Ohlcv::class, 'timeframe_id');
    }

    /**
     * Get the renko records for this timeframe.
     */
    public function renkoRecords(): HasMany
    {
        return $this->hasMany(Renko::class, 'timeframe_id');
    }
}
