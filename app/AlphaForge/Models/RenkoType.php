<?php

namespace App\AlphaForge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $method
 * @property string $brick_size
 * @property array<string, mixed>|null $params
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 */
class RenkoType extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'alphaforge_renko_types';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'method',
        'brick_size',
        'params',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'brick_size' => 'decimal:12',
        'params' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the renko records for this renko type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\AlphaForge\Models\Renko>
     */
    public function renkoRecords(): HasMany
    {
        return $this->hasMany(Renko::class, 'renko_type_id');
    }

    /**
     * Scope a query to filter by method.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByMethod($query, string $method): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('method', $method);
    }
}
