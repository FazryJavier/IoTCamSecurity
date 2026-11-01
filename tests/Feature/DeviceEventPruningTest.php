<?php

namespace Tests\Feature;

use App\Models\DeviceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceEventPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_events_and_their_snapshots_are_pruned()
    {
        Storage::fake(DeviceEvent::SNAPSHOT_DISK);
        config(['services.events.retention_days' => 30]);

        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->put('snapshots/old.jpg', 'x');
        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->put('snapshots/new.jpg', 'y');
        $old = DeviceEvent::factory()->create(['occurred_at' => now()->subDays(31), 'snapshot_path' => 'snapshots/old.jpg']);
        $recent = DeviceEvent::factory()->create(['occurred_at' => now()->subDays(29), 'snapshot_path' => 'snapshots/new.jpg']);

        $this->artisan('model:prune', ['--model' => [DeviceEvent::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->assertMissing('snapshots/old.jpg');
        Storage::disk(DeviceEvent::SNAPSHOT_DISK)->assertExists('snapshots/new.jpg');
    }
}
