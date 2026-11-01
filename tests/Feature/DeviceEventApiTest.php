<?php

namespace Tests\Feature;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use App\Models\DeviceEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceEventApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DeviceEvent::SNAPSHOT_DISK);
    }

    private function user(): User
    {
        return User::factory()->create(['username' => 'operator']);
    }

    public function test_guest_gets_json_401_envelope()
    {
        $this->getJson('/events')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 401)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors.0.code', 'unauthenticated');

        $this->postJson('/events', ['type' => 'camera_on', 'source' => 'laptop'])->assertStatus(401);
        $this->assertDatabaseCount('device_events', 0);
    }

    public function test_guest_browser_request_is_redirected_to_login()
    {
        $this->get('/events')->assertRedirect('/');
    }

    public function test_index_returns_latest_events_with_pagination_meta()
    {
        DeviceEvent::factory()->create(['type' => DeviceEventType::DeviceOnline, 'occurred_at' => now()->subMinutes(5)]);
        DeviceEvent::factory()->create(['type' => DeviceEventType::MotionStarted, 'occurred_at' => now()->subMinute()]);
        DeviceEvent::factory()->create(['type' => DeviceEventType::MotionStarted, 'occurred_at' => now()->subDays(2)]);

        $this->actingAs($this->user())
            ->getJson('/events?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors', null)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'motion_started')
            ->assertJsonPath('data.0.label', 'Motion detected')
            ->assertJsonPath('data.0.source', 'esp32')
            ->assertJsonPath('data.1.type', 'device_online')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('meta.motion_today', 1)
            ->assertJsonMissingPath('data.0.snapshot_path');
    }

    public function test_index_filters_by_category()
    {
        DeviceEvent::factory()->create(['type' => DeviceEventType::MotionStarted]);
        DeviceEvent::factory()->create(['type' => DeviceEventType::CameraOn, 'source' => CameraSource::Laptop]);
        DeviceEvent::factory()->create(['type' => DeviceEventType::SourceChanged, 'source' => CameraSource::Laptop]);

        $response = $this->actingAs($this->user())->getJson('/events?category=camera')->assertOk();

        $this->assertEqualsCanonicalizing(['camera_on', 'source_changed'], array_column($response->json('data'), 'type'));
    }

    public function test_index_rejects_unknown_filters_with_envelope()
    {
        $this->actingAs($this->user())
            ->getJson('/events?category=everything&per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('data', null)
            ->assertJsonFragment(['field' => 'category'])
            ->assertJsonFragment(['field' => 'per_page']);
    }

    public function test_camera_events_are_recorded_with_the_user()
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/events', ['type' => 'camera_on', 'source' => 'esp32'])
            ->assertStatus(201)
            ->assertJsonPath('code', 201)
            ->assertJsonPath('data.type', 'camera_on')
            ->assertJsonPath('data.user.username', 'operator');

        $this->assertDatabaseHas('device_events', ['type' => 'camera_on', 'source' => 'esp32', 'user_id' => $user->id]);
    }

    public function test_source_change_requires_and_stores_previous_source()
    {
        $this->actingAs($this->user())
            ->postJson('/events', ['type' => 'source_changed', 'source' => 'laptop'])
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'previous_source']);

        $this->postJson('/events', ['type' => 'source_changed', 'source' => 'laptop', 'previous_source' => 'esp32'])
            ->assertStatus(201)
            ->assertJsonPath('data.payload', ['from' => 'esp32', 'to' => 'laptop']);
    }

    public function test_laptop_motion_with_snapshot_is_stored_and_served()
    {
        $this->actingAs($this->user());

        $response = $this->post('/events', [
            'type' => 'motion_started',
            'source' => 'laptop',
            'snapshot' => UploadedFile::fake()->image('snapshot.jpg', 320, 240),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $event = DeviceEvent::firstOrFail();
        $this->assertNotNull($event->snapshot_path);
        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->assertExists($event->snapshot_path);
        $this->assertSame(route('events.snapshot', $event), $response->json('data.snapshot_url'));

        $this->get($response->json('data.snapshot_url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_snapshot_endpoint_returns_404_without_file_and_requires_login()
    {
        $event = DeviceEvent::factory()->create();

        $this->getJson(route('events.snapshot', $event))->assertStatus(401);

        $this->actingAs($this->user())
            ->getJson(route('events.snapshot', $event))
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_rejects_events_the_browser_must_not_report()
    {
        $this->actingAs($this->user());

        // presence comes only from the device via MQTT
        $this->postJson('/events', ['type' => 'device_online', 'source' => 'esp32'])
            ->assertStatus(422)->assertJsonFragment(['field' => 'type']);

        // ESP32 motion comes from the PIR via MQTT, not from the browser
        $this->postJson('/events', ['type' => 'motion_started', 'source' => 'esp32'])
            ->assertStatus(422)->assertJsonFragment(['field' => 'source']);

        $this->assertDatabaseCount('device_events', 0);
    }

    public function test_rejects_invalid_snapshots()
    {
        $this->actingAs($this->user());
        $json = ['Accept' => 'application/json'];

        $this->post('/events', [
            'type' => 'motion_started',
            'source' => 'laptop',
            'snapshot' => UploadedFile::fake()->image('snapshot.png'),
        ], $json)->assertStatus(422)->assertJsonFragment(['field' => 'snapshot']);

        $this->post('/events', [
            'type' => 'motion_started',
            'source' => 'laptop',
            'snapshot' => UploadedFile::fake()->create('snapshot.jpg', 2048, 'image/jpeg'),
        ], $json)->assertStatus(422)->assertJsonFragment(['field' => 'snapshot']);

        $this->post('/events', [
            'type' => 'camera_on',
            'source' => 'laptop',
            'snapshot' => UploadedFile::fake()->image('snapshot.jpg'),
        ], $json)->assertStatus(422)->assertJsonFragment(['field' => 'snapshot']);

        $this->assertDatabaseCount('device_events', 0);
        $this->assertEmpty(Storage::disk(DeviceEvent::SNAPSHOT_DISK)->allFiles());
    }

    public function test_repeated_motion_reports_are_collapsed_and_duration_recorded()
    {
        $this->actingAs($this->user());
        $this->travelTo(now()->startOfMinute());

        $this->postJson('/events', ['type' => 'motion_started', 'source' => 'laptop'])->assertStatus(201);
        $this->postJson('/events', ['type' => 'motion_started', 'source' => 'laptop'])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->travel(12)->seconds();
        $this->postJson('/events', ['type' => 'motion_stopped', 'source' => 'laptop'])
            ->assertStatus(201)
            ->assertJsonPath('data.payload.duration_seconds', 12);

        $this->assertDatabaseCount('device_events', 2);
    }
}
