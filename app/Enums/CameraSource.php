<?php

namespace App\Enums;

enum CameraSource: string
{
    case Esp32 = 'esp32';
    case Laptop = 'laptop';

    public function label(): string
    {
        return match ($this) {
            self::Esp32 => 'ESP32-CAM',
            self::Laptop => 'Laptop camera',
        };
    }
}
