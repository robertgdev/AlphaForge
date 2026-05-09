<?php

namespace App\AlphaForge\Data\Model;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $exchange_id
 * @property string $symbol
 * @property string $timeframe
 * @property string $type
 * @property string $status
 * @property int $records_expected
 * @property int $records_downloaded
 * @property array|null $error_log
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface|null $completed_at
 * @property string|null $file_path
 * @property int|null $file_size
 * @property int|null $bars_count
 * @property string|null $error_message
 * @property \DateTimeInterface|null $started_at
 */
class MarketDataDownload extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'alphaforge_data_downloads';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'exchange_id',
        'symbol',
        'timeframe',
        'type',
        'status',
        'records_expected',
        'records_downloaded',
        'error_log',
        'completed_at',
        'file_path',
        'file_size',
        'bars_count',
        'error_message',
        'started_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'records_expected' => 'integer',
        'records_downloaded' => 'integer',
        'error_log' => 'array',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'started_at' => 'datetime',
        'file_size' => 'integer',
        'bars_count' => 'integer',
    ];

    /**
     * Get the user that owns this download.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User>
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