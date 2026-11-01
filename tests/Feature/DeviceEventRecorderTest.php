<?php

namespace Tests\Feature;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use App\Models\DeviceEvent;
use App\Services\DeviceEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFFfake-jpeg";

    private DeviceEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DeviceEvent::SNAPSHOT_DISK);
        config([
            'services.mqtt.topic_prefix' => 'seccam',
            'services.camera.snapshot_url' => 'http://10.0.0.5/jpg',
        ]);
        $this->recorder = app(DeviceEventRecorder::class);
    }

    public function test_esp32_motion_start_is_recorded_with_snapshot()
    {
        Http::fake(['10.0.0.5/jpg' => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg'])]);

        $event = $this->recorder->handleMqttMessage('seccam/motion', '1');

        $this->assertSame(DeviceEventType::MotionStarted, $event->type);
        $this->assertSame(CameraSource::Esp32, $event->source);
        $this->assertNull($event->user_id);
        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->assertExists($event->snapshot_path);
        $this->assertSame(self::JPEG, Storage::disk(DeviceEvent::SNAPSHOT_DISK)->get($event->snapshot_path));
    }

    public function test_snapshot_failure_does_not_block_the_event()
    {
        Http::fake(['10.0.0.5/jpg' => Http::response('<html>oops</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->assertNull($this->recorder->handleMqttMessage('seccam/motion', '1')->snapshot_path);

        DeviceEvent::query()->delete();
        Http::fake(['10.0.0.5/jpg' => Http::response('', 500)]);
        $this->assertNull($this->recorder->handleMqttMessage('seccam/motion', '1')->snapshot_path);

        DeviceEvent::query()->delete();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertNull($this->recorder->handleMqttMessage('seccam/motion', '1')->snapshot_path);

        $this->assertDatabaseCount('device_events', 1);
    }

    public function test_duplicate_retained_and_invalid_messages_are_ignored()
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertNull($this->recorder->handleMqttMessage('seccam/motion', '1', retained: true));
        $this->assertNotNull($this->recorder->handleMqttMessage('seccam/motion', '1'));
        $this->assertNull($this->recorder->handleMqttMessage('seccam/motion', '1'));
        $this->assertNull($this->recorder->handleMqttMessage('seccam/motion', 'yes'));
        $this->assertNull($this->recorder->handleMqttMessage('seccam/servo/pan', '90'));
        $this->assertNull($this->recorder->handleMqttMessage('other/motion', '1'));

        $this->assertDatabaseCount('device_events', 1);
    }

    public function test_motion_stop_records_duration()
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->travelTo(now()->startOfMinute());

        $this->recorder->handleMqttMessage('seccam/motion', '1');
        $this->travel(7)->seconds();
        $stopped = $this->recorder->handleMqttMessage('seccam/motion', '0');

        $this->assertSame(DeviceEventType::MotionStopped, $stopped->type);
        $this->assertSame(['duration_seconds' => 7], $stopped->payload);
    }

    public function test_device_presence_changes_are_deduplicated()
    {
        $this->assertSame(DeviceEventType::DeviceOnline, $this->recorder->handleMqttMessage('seccam/status', 'online')->type);
        $this->assertNull($this->recorder->handleMqttMessage('seccam/status', 'online'));
        $this->assertSame(DeviceEventType::DeviceOffline, $this->recorder->handleMqttMessage('seccam/status', 'offline')->type);
        $this->assertNull($this->recorder->handleMqttMessage('seccam/status', 'rebooting'));

        $this->assertDatabaseCount('device_events', 2);
    }

    public function test_laptop_and_esp32_motion_states_are_independent()
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->recorder->record(DeviceEventType::MotionStarted, CameraSource::Laptop);

        $this->assertNotNull($this->recorder->handleMqttMessage('seccam/motion', '1'));
        $this->assertSame(DeviceEventType::MotionStarted, $this->recorder->lastMotionType(CameraSource::Laptop));
    }
}
