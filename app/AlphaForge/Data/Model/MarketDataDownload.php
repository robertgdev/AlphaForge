<?php

namespace App\AlphaForge\Data\Model;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketDataDownload extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'symbol',
        'timeframe',
        'exchange',
        'start_date',
        'end_date',
        'status',
        'file_path',
        'file_size',
        'bars_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the download.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the download is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the download is in progress.
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, ['downloading', 'processing']);
    }

    /**
     * Check if the download is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the download has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark the download as downloading.
     */
    public function markAsDownloading(): void
    {
        $this->update([
            'status' => 'downloading',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark the download as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark the download as completed.
     */
    public function markAsCompleted(string $filePath, int $fileSize, int $barsCount): void
    {
        $this->update([
            'status' => 'completed',
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'bars_count' => $barsCount,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the download as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
