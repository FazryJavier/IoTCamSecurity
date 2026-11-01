<?php

namespace App\Http\Controllers;

use App\Enums\CameraSource;

class DashboardController extends Controller
{
    public function index() {
        $mqtt = config('services.mqtt');
        $prefix = trim($mqtt['topic_prefix'], '/');

        return view('screen.HomePage', [
            'dashboardConfig' => [
                'streamUrl' => config('services.camera.stream_url'),
                'defaultSource' => CameraSource::tryFrom((string) config('services.camera.default_source'))?->value
                    ?? CameraSource::Esp32->value,
                'routes' => [
                    'events' => route('events.index'),
                ],
                'mqtt' => [
                    'host' => $mqtt['ws_host'],
                    'port' => $mqtt['ws_port'],
                    'path' => $mqtt['ws_path'],
                    'useSSL' => $mqtt['ws_tls'],
                    'username' => $mqtt['username'],
                    'password' => $mqtt['password'],
                    'topics' => [
                        'pan' => "{$prefix}/servo/pan",
                        'tilt' => "{$prefix}/servo/tilt",
                        'motion' => "{$prefix}/motion",
                        'status' => "{$prefix}/status",
                    ],
                ],
            ],
        ]);
    }
}
