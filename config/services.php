<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'camera' => [
        // MJPEG stream served by the ESP32-CAM sketch (esp32_camera_mjpeg_multiclient).
        'stream_url' => env('CAMERA_STREAM_URL', 'http://192.168.38.184/mjpeg/1'),
        // Still frame grabbed when the ESP32 reports motion (empty = derived from stream_url).
        'snapshot_url' => env('CAMERA_SNAPSHOT_URL') ?: preg_replace('#/mjpeg/\d+$#', '/jpg', (string) env('CAMERA_STREAM_URL', 'http://192.168.38.184/mjpeg/1')),
        // Source preselected on the dashboard: esp32 | laptop
        'default_source' => env('CAMERA_DEFAULT_SOURCE', 'esp32'),
    ],

    'mqtt' => [
        // WebSocket listener of the MQTT broker, reached from the browser.
        'ws_host' => env('MQTT_WS_HOST', 'localhost'),
        'ws_port' => (int) env('MQTT_WS_PORT', 8083),
        'ws_path' => env('MQTT_WS_PATH', '/mqtt'),
        'ws_tls' => (bool) env('MQTT_WS_TLS', false),
        'username' => env('MQTT_WS_USERNAME'),
        'password' => env('MQTT_WS_PASSWORD'),
        // Must match MQTT_TOPIC_PREFIX in the ESP32 sketch.
        'topic_prefix' => env('MQTT_TOPIC_PREFIX', 'seccam'),
        // TCP connection used by `php artisan mqtt:listen` to store ESP32 events.
        'listener' => [
            'host' => env('MQTT_LISTENER_HOST', '127.0.0.1'),
            'port' => (int) env('MQTT_BROKER_TCP_PORT', 1883),
            'username' => env('MQTT_BROKER_USERNAME'),
            'password' => env('MQTT_BROKER_PASSWORD'),
        ],
    ],

    'events' => [
        // Device events and snapshots older than this are pruned daily.
        'retention_days' => (int) env('EVENTS_RETENTION_DAYS', 30),
    ],

];
