<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_camera_and_mqtt_config()
    {
        config([
            'services.camera.stream_url' => 'http://10.0.0.5/mjpeg/1',
            'services.mqtt.ws_host' => 'broker.local',
            'services.mqtt.ws_port' => 9001,
            'services.mqtt.topic_prefix' => 'lab',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/home')
            ->assertOk()
            ->assertSee('http:\/\/10.0.0.5\/mjpeg\/1', false)
            ->assertSee('"host":"broker.local"', false)
            ->assertSee('"port":9001', false)
            ->assertSee('lab\/servo\/pan', false)
            ->assertSee('lab\/servo\/tilt', false)
            ->assertSee('lab\/motion', false)
            ->assertSee('id="pan"', false)
            ->assertSee('id="tilt"', false);
    }

    public function test_dashboard_escapes_config_values()
    {
        config(['services.camera.stream_url' => '</script><script>alert(1)</script>']);

        $this->actingAs(User::factory()->create())
            ->get('/home')
            ->assertOk()
            ->assertDontSee('</script><script>alert(1)', false);
    }

    public function test_dashboard_offers_both_camera_sources()
    {
        config(['services.camera.default_source' => 'laptop']);

        $this->actingAs(User::factory()->create())
            ->get('/home')
            ->assertOk()
            ->assertSee('id="sourceEsp32"', false)
            ->assertSee('id="sourceLaptop"', false)
            ->assertSee('"defaultSource":"laptop"', false)
            ->assertSee('id="sensitivity"', false)
            ->assertSee('id="zoom"', false);
    }

    public function test_unknown_default_source_falls_back_to_esp32()
    {
        config(['services.camera.default_source' => 'webcam']);

        $this->actingAs(User::factory()->create())
            ->get('/home')
            ->assertSee('"defaultSource":"esp32"', false);
    }
}
