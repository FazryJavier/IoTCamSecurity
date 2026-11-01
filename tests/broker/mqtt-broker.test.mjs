// Integration tests for scripts/mqtt-broker.mjs: `npm run test:broker`
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { once } from 'node:events';
import mqtt from 'mqtt';

const BROKER = resolve('scripts/mqtt-broker.mjs');
const brokers = [];

async function startBroker(tcpPort, wsPort, extraEnv = {}) {
    // run from an empty dir so the project's .env is not picked up
    const cwd = mkdtempSync(join(tmpdir(), 'seccam-broker-'));
    const child = spawn(process.execPath, [BROKER], {
        cwd,
        env: {
            ...process.env,
            MQTT_BROKER_TCP_PORT: String(tcpPort),
            MQTT_BROKER_WS_PORT: String(wsPort),
            MQTT_TOPIC_PREFIX: 'seccam',
            MQTT_BROKER_USERNAME: '',
            MQTT_BROKER_PASSWORD: '',
            ...extraEnv,
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    brokers.push(child);
    let out = '';
    await new Promise((resolveReady, reject) => {
        child.stdout.on('data', (d) => {
            out += d;
            if (out.includes('MQTT TCP') && out.includes('MQTT WebSocket')) resolveReady();
        });
        child.on('exit', (code) => reject(new Error(`broker exited ${code}: ${out}`)));
    });
    return child;
}

async function connect(url, opts = {}) {
    const client = mqtt.connect(url, { reconnectPeriod: 0, connectTimeout: 3000, ...opts });
    await Promise.race([
        once(client, 'connect'),
        once(client, 'error').then(([err]) => { throw err; }),
    ]);
    return client;
}

before(async () => {
    await startBroker(18830, 18083);
    await startBroker(18831, 18084, { MQTT_BROKER_USERNAME: 'cam', MQTT_BROKER_PASSWORD: 's3cret' });
});

after(() => {
    for (const child of brokers) child.kill();
});

test('ESP32 (TCP) and browser (WebSocket) exchange servo commands', async () => {
    const esp = await connect('mqtt://127.0.0.1:18830');
    const web = await connect('ws://127.0.0.1:18083/mqtt');
    await esp.subscribeAsync('seccam/servo/pan');

    const received = once(esp, 'message');
    await web.publishAsync('seccam/servo/pan', '42');
    const [topic, payload] = await received;

    assert.equal(topic, 'seccam/servo/pan');
    assert.equal(payload.toString(), '42');
    esp.end(); web.end();
});

test('retained position is delivered to late subscribers', async () => {
    const web = await connect('ws://127.0.0.1:18083/mqtt');
    await web.publishAsync('seccam/servo/tilt', '120', { retain: true });
    web.end();

    const esp = await connect('mqtt://127.0.0.1:18830');
    const received = once(esp, 'message');
    await esp.subscribeAsync('seccam/servo/tilt');
    const [, payload, packet] = await received;

    assert.equal(payload.toString(), '120');
    assert.equal(packet.retain, true);
    esp.end();
});

test('subscriptions outside the topic prefix are refused', async () => {
    const client = await connect('mqtt://127.0.0.1:18830');

    // mqtt.js rejects when any SUBACK code is 128; the raw packet carries the codes
    const error = await client.subscribeAsync(['#', 'other/topic', 'seccam/#']).then(
        () => assert.fail('expected the subscription to be refused'),
        (err) => err,
    );
    assert.deepEqual(error.packet.granted, [128, 128, 0]);

    // the client stays connected and the allowed subscription works
    // (retained messages from earlier tests may arrive first, so wait for this topic)
    const received = new Promise((done) => {
        client.on('message', (topic, payload) => {
            if (topic === 'seccam/motion') done(payload.toString());
        });
    });
    await client.publishAsync('seccam/motion', '1');
    assert.equal(await received, '1');
    client.end();
});

test('publishing outside the topic prefix disconnects the client', async () => {
    const client = await connect('mqtt://127.0.0.1:18830');
    const closed = once(client, 'close');
    client.publish('other/topic', 'x');
    await closed;
    client.end(true);
});

test('broker with credentials rejects anonymous and wrong passwords', async () => {
    await assert.rejects(connect('mqtt://127.0.0.1:18831'), /Not authorized|Bad username/i);
    await assert.rejects(
        connect('mqtt://127.0.0.1:18831', { username: 'cam', password: 'wrong' }),
        /Not authorized|Bad username/i,
    );

    const ok = await connect('mqtt://127.0.0.1:18831', { username: 'cam', password: 's3cret' });
    assert.ok(ok.connected);
    ok.end();
});
