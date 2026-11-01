<?php

namespace Database\Factories;

use App\Enums\CameraSource;
use App\Enums\DeviceEventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceEvent>
 */
class DeviceEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => DeviceEventType::MotionStarted,
            'source' => CameraSource::Esp32,
            'payload' => null,
            'snapshot_path' => null,
            'user_id' => null,
            'occurred_at' => now(),
        ];
    }
}
