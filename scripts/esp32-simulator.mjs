// Fake ESP32-CAM for testing the dashboard without hardware: `npm run simulate`.
// Speaks the same MQTT topics as esp32_camera_mjpeg_multiclient.ino:
//   subscribes <prefix>/servo/pan, <prefix>/servo/tilt  -> prints the angle it would move to
//   publishes  <prefix>/status (online / offline as last will), <prefix>/motion (1 / 0)

import mqtt from 'mqtt';

try {
    process.loadEnvFile('.env');
} catch {
    // no .env: fall back to defaults below
}

const env = process.env;
const PREFIX = (env.MQTT_TOPIC_PREFIX || 'seccam').replace(/\/+$/, '');
const URL = env.SIMULATOR_MQTT_URL || `mqtt://127.0.0.1:${env.MQTT_BROKER_TCP_PORT || 1883}`;
const LIMITS = { pan: [0, 180], tilt: [40, 150] };
const topics = {
    pan: `${PREFIX}/servo/pan`,
    tilt: `${PREFIX}/servo/tilt`,
    motion: `${PREFIX}/motion`,
    status: `${PREFIX}/status`,
};

const client = mqtt.connect(URL, {
    clientId: 'seccam-esp32-simulator',
    username: env.MQTT_BROKER_USERNAME || undefined,
    password: env.MQTT_BROKER_PASSWORD || undefined,
    will: { topic: topics.status, payload: 'offline', retain: true, qos: 0 },
    reconnectPeriod: 3000,
});

let motionTimer = null;

client.on('connect', () => {
    console.log(`connected to ${URL}`);
    client.publish(topics.status, 'online', { retain: true });
    client.subscribe([topics.pan, topics.tilt]);

    clearInterval(motionTimer);
    let motion = 0;
    motionTimer = setInterval(() => {
        // toggle roughly every 5-15 s, like a PIR sensor
        if (Math.random() < 0.3) {
            motion = motion ? 0 : 1;
            client.publish(topics.motion, String(motion), { retain: true });
            console.log(motion ? 'Motion detected!' : 'Motion stopped!');
        }
    }, 3000);
});

client.on('message', (topic, payload) => {
    const axis = topic === topics.pan ? 'pan' : 'tilt';
    const text = payload.toString();
    if (!/^\d{1,3}$/.test(text)) {
        console.log(`Ignoring invalid payload on ${topic}: ${text}`);
        return;
    }
    const [min, max] = LIMITS[axis];
    const angle = Math.min(max, Math.max(min, Number(text)));
    console.log(`${axis === 'pan' ? 'Pan ' : 'Tilt'} -> ${angle}`);
});

client.on('error', (err) => console.error('MQTT error:', err.message));
client.on('offline', () => console.log('broker unreachable, retrying...'));

const shutdown = () => {
    clearInterval(motionTimer);
    client.publish(topics.status, 'offline', { retain: true }, () => client.end(false, () => process.exit(0)));
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
