<?php

namespace App\Services;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use App\Models\DeviceEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single entry point for writing security events, whether they come from the
 * ESP32 over MQTT (mqtt:listen) or from the dashboard (laptop camera mode).
 */
class DeviceEventRecorder
{
    private const MAX_SNAPSHOT_BYTES = 2 * 1024 * 1024;

    public function record(
        DeviceEventType $type,
        CameraSource $source,
        ?array $payload = null,
        ?User $user = null,
        UploadedFile|string|null $snapshot = null,
        ?Carbon $occurredAt = null,
    ): DeviceEvent {
        $occurredAt ??= now();

        if ($type === DeviceEventType::MotionStopped) {
            $payload = array_merge($payload ?? [], $this->motionDuration($source, $occurredAt));
        }

        return DeviceEvent::create([
            'type' => $type,
            'source' => $source,
            'payload' => $payload ?: null,
            'snapshot_path' => $snapshot ? $this->storeSnapshot($snapshot, $occurredAt) : null,
            'user_id' => $user?->id,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Handles one MQTT message from the ESP32. Returns the recorded event, or null
     * when the message is ignored (retained replay, duplicate state, unknown topic).
     */
    public function handleMqttMessage(string $topic, string $message, bool $retained = false): ?DeviceEvent
    {
        // Retained messages replay the last known state on (re)connect; they are not new events.
        if ($retained) {
            return null;
        }

        $prefix = trim(config('services.mqtt.topic_prefix'), '/');
        $message = trim($message);

        return match ($topic) {
            "{$prefix}/motion" => $this->handleEsp32Motion($message),
            "{$prefix}/status" => $this->handleEsp32Status($message),
            default => null,
        };
    }

    private function handleEsp32Motion(string $message): ?DeviceEvent
    {
        $type = match ($message) {
            '1' => DeviceEventType::MotionStarted,
            '0' => DeviceEventType::MotionStopped,
            default => null,
        };

        if ($type === null || $this->lastMotionType(CameraSource::Esp32) === $type) {
            return null;
        }

        $snapshot = $type === DeviceEventType::MotionStarted ? $this->fetchEsp32Snapshot() : null;

        return $this->record($type, CameraSource::Esp32, snapshot: $snapshot);
    }

    private function handleEsp32Status(string $message): ?DeviceEvent
    {
        $type = match ($message) {
            'online' => DeviceEventType::DeviceOnline,
            'offline' => DeviceEventType::DeviceOffline,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $last = DeviceEvent::query()
            ->where('source', CameraSource::Esp32)
            ->whereIn('type', [DeviceEventType::DeviceOnline, DeviceEventType::DeviceOffline])
            ->latest('occurred_at')->latest('id')
            ->value('type');

        if ($last === $type) {
            return null;
        }

        return $this->record($type, CameraSource::Esp32);
    }

    public function lastMotionType(CameraSource $source): ?DeviceEventType
    {
        return DeviceEvent::query()
            ->where('source', $source)
            ->whereIn('type', [DeviceEventType::MotionStarted, DeviceEventType::MotionStopped])
            ->latest('occurred_at')->latest('id')
            ->value('type');
    }

    /**
     * @return array{duration_seconds?: int}
     */
    private function motionDuration(CameraSource $source, Carbon $stoppedAt): array
    {
        $started = DeviceEvent::query()
            ->where('source', $source)
            ->where('type', DeviceEventType::MotionStarted)
            ->latest('occurred_at')->latest('id')
            ->first();

        if (! $started || $started->occurred_at->greaterThan($stoppedAt)) {
            return [];
        }

        return ['duration_seconds' => (int) $started->occurred_at->diffInSeconds($stoppedAt, true)];
    }

    /**
     * Grabs a still frame from the ESP32 (/jpg). Failure never blocks recording the event.
     */
    private function fetchEsp32Snapshot(): ?string
    {
        $url = config('services.camera.snapshot_url');
        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(3)->connectTimeout(2)->withOptions(['allow_redirects' => false])->get($url);
        } catch (Throwable $e) {
            Log::warning('ESP32 snapshot request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $body = $response->body();
        if (! $response->successful()
            || ! str_starts_with((string) $response->header('Content-Type'), 'image/jpeg')
            || strlen($body) === 0
            || strlen($body) > self::MAX_SNAPSHOT_BYTES) {
            Log::warning('ESP32 snapshot rejected', ['status' => $response->status()]);

            return null;
        }

        return $body;
    }

    private function storeSnapshot(UploadedFile|string $snapshot, Carbon $occurredAt): string
    {
        $path = 'snapshots/'.$occurredAt->format('Y/m/d').'/'.Str::uuid().'.jpg';
        $contents = $snapshot instanceof UploadedFile ? $snapshot->get() : $snapshot;

        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->put($path, $contents);

        return $path;
    }
}
