// Local MQTT broker for development: `npm run broker` (also started by `composer dev`).
//
//   TCP       0.0.0.0:MQTT_BROKER_TCP_PORT (1883)  <- ESP32-CAM (PubSubClient)
//   WebSocket 0.0.0.0:MQTT_BROKER_WS_PORT  (8083)  <- dashboard in the browser (Paho)
//
// Settings are read from .env. When MQTT_BROKER_USERNAME is set, clients must log in
// with it; otherwise the broker is anonymous (fine for a home/lab network only).
// Clients may only publish/subscribe under MQTT_TOPIC_PREFIX/#.

import { createServer as createTcpServer } from 'node:net';
import { createServer as createHttpServer } from 'node:http';
import { Aedes } from 'aedes';
import { WebSocketServer, createWebSocketStream } from 'ws';

try {
    process.loadEnvFile('.env');
} catch {
    // no .env: fall back to defaults below
}

const env = process.env;
const TCP_PORT = Number(env.MQTT_BROKER_TCP_PORT || 1883);
const WS_PORT = Number(env.MQTT_BROKER_WS_PORT || 8083);
const PREFIX = (env.MQTT_TOPIC_PREFIX || 'seccam').replace(/\/+$/, '');
const USERNAME = env.MQTT_BROKER_USERNAME || '';
const PASSWORD = env.MQTT_BROKER_PASSWORD || '';

const allowedTopic = (topic) => topic === PREFIX || topic.startsWith(PREFIX + '/');
const time = () => new Date().toLocaleTimeString();
const log = (...args) => console.log(`[${time()}]`, ...args);

// Handlers must be passed as options: aedes 1.x reads them when the broker is created.
const aedes = await Aedes.createBroker({
    drainTimeout: 30000,

    authenticate(client, username, password, callback) {
        if (!USERNAME) {
            return callback(null, true);
        }
        const ok = username === USERNAME && password?.toString() === PASSWORD;
        if (!ok) {
            log(`auth failed for client ${client?.id}`);
            const error = new Error('Bad username or password');
            error.returnCode = 4;
            return callback(error, false);
        }
        callback(null, true);
    },

    // Rejected publishes close the offending client (MQTT-3.3.5-2).
    authorizePublish(client, packet, callback) {
        if (client && !allowedTopic(packet.topic)) {
            log(`denied publish ${client.id} -> ${packet.topic}`);
            return callback(new Error(`publish to ${packet.topic} not allowed`));
        }
        callback(null);
    },

    // Rejected subscriptions get SUBACK 128 instead of dropping the connection.
    authorizeSubscribe(client, sub, callback) {
        if (!allowedTopic(sub.topic)) {
            log(`denied subscribe ${client?.id} -> ${sub.topic}`);
            return callback(null, null);
        }
        callback(null, sub);
    },
});

aedes.on('client', (client) => log(`connected    ${client.id}`));
aedes.on('clientDisconnect', (client) => log(`disconnected ${client.id}`));
aedes.on('publish', (packet, client) => {
    if (client) {
        log(`${client.id} -> ${packet.topic} = ${packet.payload.toString().slice(0, 40)}`);
    }
});

const tcpServer = createTcpServer(aedes.handle);
tcpServer.listen(TCP_PORT, '0.0.0.0', () => log(`MQTT TCP       listening on 0.0.0.0:${TCP_PORT} (ESP32)`));

const httpServer = createHttpServer();
const wss = new WebSocketServer({ server: httpServer });
wss.on('connection', (websocket, req) => {
    aedes.handle(createWebSocketStream(websocket), req);
});
httpServer.listen(WS_PORT, '0.0.0.0', () => log(`MQTT WebSocket listening on 0.0.0.0:${WS_PORT} (browser)`));

for (const server of [tcpServer, httpServer]) {
    server.on('error', (err) => {
        console.error(`Broker failed to start: ${err.message}`);
        if (err.code === 'EADDRINUSE') {
            console.error('Another MQTT broker (Mosquitto/EMQX) is probably already running on that port.');
        }
        process.exit(1);
    });
}

log(`topic prefix "${PREFIX}/#", ${USERNAME ? 'username/password required' : 'anonymous access'}`);

const shutdown = () => {
    wss.close();
    httpServer.close();
    tcpServer.close();
    aedes.close(() => process.exit(0));
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
