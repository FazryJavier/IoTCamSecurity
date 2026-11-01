<?php

namespace App\Enums;

enum DeviceEventType: string
{
    case MotionStarted = 'motion_started';
    case MotionStopped = 'motion_stopped';
    case DeviceOnline = 'device_online';
    case DeviceOffline = 'device_offline';
    case CameraOn = 'camera_on';
    case CameraOff = 'camera_off';
    case SourceChanged = 'source_changed';

    /**
     * Event types the dashboard may report itself. Device online/offline only
     * ever comes from the broker, never from a browser.
     *
     * @return list<self>
     */
    public static function reportableByClient(): array
    {
        return [
            self::MotionStarted,
            self::MotionStopped,
            self::CameraOn,
            self::CameraOff,
            self::SourceChanged,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::MotionStarted => 'Motion detected',
            self::MotionStopped => 'Motion stopped',
            self::DeviceOnline => 'Device online',
            self::DeviceOffline => 'Device offline',
            self::CameraOn => 'Camera on',
            self::CameraOff => 'Camera off',
            self::SourceChanged => 'Camera source changed',
        };
    }
}
