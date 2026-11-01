<?php

namespace App\Http\Controllers;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use App\Helpers\ApiFormatter;
use App\Http\Requests\StoreDeviceEventRequest;
use App\Models\DeviceEvent;
use App\Services\DeviceEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceEventController extends Controller
{
    private const CATEGORIES = [
        'motion' => ['motion_started', 'motion_stopped'],
        'camera' => ['camera_on', 'camera_off', 'source_changed'],
        'device' => ['device_online', 'device_offline'],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(DeviceEventType::class)],
            'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
            'source' => ['nullable', Rule::enum(CameraSource::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $events = DeviceEvent::query()
            ->with('user:id,username')
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->whereIn('type', self::CATEGORIES[$category]))
            ->when($validated['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->latest('occurred_at')->latest('id')
            ->paginate($validated['per_page'] ?? 20);

        return ApiFormatter::createApi(
            200,
            'Device events retrieved.',
            $events->getCollection()->map->toApiArray()->values(),
            null,
            [
                'page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'total_pages' => $events->lastPage(),
                'motion_today' => DeviceEvent::where('type', DeviceEventType::MotionStarted)
                    ->where('occurred_at', '>=', now()->startOfDay())
                    ->count(),
                'last_motion_at' => DeviceEvent::where('type', DeviceEventType::MotionStarted)
                    ->latest('occurred_at')
                    ->value('occurred_at')
                    ?->toIso8601String(),
            ],
        );
    }

    public function store(StoreDeviceEventRequest $request, DeviceEventRecorder $recorder): JsonResponse
    {
        $type = DeviceEventType::from($request->validated('type'));
        $source = CameraSource::from($request->validated('source'));

        // Repeated start/stop reports for the same source are collapsed.
        if (in_array($type, [DeviceEventType::MotionStarted, DeviceEventType::MotionStopped], true)
            && $recorder->lastMotionType($source) === $type) {
            return ApiFormatter::createApi(200, 'Motion state unchanged, nothing recorded.', null);
        }

        $payload = $type === DeviceEventType::SourceChanged
            ? ['from' => $request->validated('previous_source'), 'to' => $source->value]
            : null;

        $event = $recorder->record($type, $source, $payload, $request->user(), $request->file('snapshot'));

        return ApiFormatter::createApi(201, 'Device event recorded.', $event->load('user:id,username')->toApiArray());
    }

    public function snapshot(DeviceEvent $event): StreamedResponse
    {
        $disk = Storage::disk(DeviceEvent::SNAPSHOT_DISK);
        abort_if(! $event->snapshot_path || ! $disk->exists($event->snapshot_path), 404);

        return $disk->response($event->snapshot_path, "event-{$event->id}.jpg", [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
