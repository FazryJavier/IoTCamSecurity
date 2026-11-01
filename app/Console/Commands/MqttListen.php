<?php

namespace App\Console\Commands;

use App\Services\DeviceEventRecorder;
use Illuminate\Console\Command;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Long-running ingest worker: stores ESP32 motion / presence events in the
 * database even when nobody has the dashboard open. Started by `composer dev`.
 */
class MqttListen extends Command
{
    protected $signature = 'mqtt:listen
        {--retry=5 : Seconds to wait before reconnecting after a failure}';

    protected $description = 'Subscribe to the ESP32 MQTT topics and record security events';

    public function handle(DeviceEventRecorder $recorder): int
    {
        $config = config('services.mqtt.listener');
        $prefix = trim(config('services.mqtt.topic_prefix'), '/');
        $retry = max(1, (int) $this->option('retry'));

        while (true) {
            $client = new MqttClient(
                $config['host'],
                (int) $config['port'],
                'seccam-laravel-'.substr(md5(gethostname().getmypid()), 0, 8),
                MqttClient::MQTT_3_1_1,
            );

            try {
                $client->connect(
                    (new ConnectionSettings)
                        ->setUsername($config['username'] ?: null)
                        ->setPassword($config['password'] ?: null)
                        ->setConnectTimeout(5)
                        ->setKeepAliveInterval(30),
                    // clean session: no state kept on the broker; reconnects are
                    // handled by this loop (auto-reconnect requires a persistent session)
                    true,
                );

                $this->info("Connected to {$config['host']}:{$config['port']}, listening on {$prefix}/motion and {$prefix}/status");

                $handler = function (string $topic, string $message, bool $retained) use ($recorder) {
                    try {
                        $event = $recorder->handleMqttMessage($topic, $message, $retained);
                        if ($event) {
                            $this->line(sprintf('[%s] %s (%s)', $event->occurred_at->format('H:i:s'), $event->type->label(), $event->source->value));
                        }
                    } catch (Throwable $e) {
                        // a bad message must not kill the listener
                        report($e);
                        $this->error("Failed to record {$topic}: {$e->getMessage()}");
                    }
                };

                $client->subscribe("{$prefix}/motion", $handler);
                $client->subscribe("{$prefix}/status", $handler);
                $client->loop(true);

                return self::SUCCESS;
            } catch (MqttClientException $e) {
                $this->warn("MQTT unavailable ({$e->getMessage()}), retrying in {$retry}s...");
                sleep($retry);
            }
        }
    }
}
