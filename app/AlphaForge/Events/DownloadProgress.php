<?php

namespace App\AlphaForge\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?string $jobId,
        public string $symbol,
        public int $lastTimestamp,
        public int $recordsFetchedInBatch,
        public int $totalDuration,
        public int $currentProgress
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        if ($this->jobId === null) {
            return [];
        }

        return [
            new PresenceChannel('download.'.$this->jobId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'download.progress';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $percentComplete = $this->totalDuration > 0
            ? round(($this->currentProgress / $this->totalDuration) * 100, 2)
            : 0;

        return [
            'job_id' => $this->jobId,
            'symbol' => $this->symbol,
            'last_timestamp' => $this->lastTimestamp,
            'records_in_batch' => $this->recordsFetchedInBatch,
            'percent_complete' => min(100, $percentComplete),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
