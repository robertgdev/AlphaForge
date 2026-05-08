<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exchange extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'alphaforge_exchanges';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the markets for this exchange.
     */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class, 'exchange_id');
    }
}
