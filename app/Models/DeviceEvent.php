<?php

namespace App\Models;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DeviceEvent extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceEventFactory> */
    use HasFactory, Prunable;

    public const SNAPSHOT_DISK = 'local';

    protected $fillable = [
        'type',
        'source',
        'payload',
        'snapshot_path',
        'user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeviceEventType::class,
            'source' => CameraSource::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Events older than the retention window are deleted by `php artisan model:prune`.
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('services.events.retention_days', 30));

        return static::where('occurred_at', '<', now()->subDays($days));
    }

    protected function pruning(): void
    {
        if ($this->snapshot_path) {
            Storage::disk(self::SNAPSHOT_DISK)->delete($this->snapshot_path);
        }
    }

    /**
     * Shape exposed by the events endpoint (no storage paths).
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'payload' => $this->payload,
            'snapshot_url' => $this->snapshot_path ? route('events.snapshot', $this) : null,
            'user' => $this->user ? ['id' => $this->user->id, 'username' => $this->user->username] : null,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
