// Smart Security Cam dashboard.
// Two camera sources:
//   esp32  - MJPEG stream from the ESP32-CAM, servos + PIR over MQTT; the server
//            (php artisan mqtt:listen) records motion/presence events.
//   laptop - webcam via getUserMedia, digital pan/tilt/zoom, motion detected in the
//            browser and recorded through POST /events (with a snapshot).
import { MotionDetector } from './motion-detector.js';

const cfg = JSON.parse(document.getElementById('seccam-config').textContent);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const $ = (id) => document.getElementById(id);
const store = {
    get(key, fallback) { try { return localStorage.getItem(key) ?? fallback; } catch { return fallback; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch { /* private mode */ } },
};

const SOURCES = { esp32: 'ESP32-CAM', laptop: 'Laptop camera' };
const DEFAULT_POS = { pan: 90, tilt: 95 };

const state = {
    source: SOURCES[store.get('seccam.source')] ? store.get('seccam.source') : cfg.defaultSource,
    cameraOn: false,
    mediaStream: null,
    deviceOnline: null,
    motion: { esp32: null, laptop: null },
    logCategory: '',
    logPage: 1,
};

// ---------------------------------------------------------------- helpers
function setChip(el, text, chipState) {
    el.textContent = text;
    el.dataset.state = chipState;
}

function timeAgo(iso) {
    if (!iso) return '–';
    const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    return new Date(iso).toLocaleDateString();
}

function toast(title, detail = '', kind = '') {
    const el = document.createElement('div');
    el.className = `toast ${kind ? 'toast--' + kind : ''}`;
    el.setAttribute('role', 'status');
    el.innerHTML = '<svg><use href="#i-motion"/></svg><div><b></b><span></span></div>';
    el.querySelector('b').textContent = title;
    el.querySelector('span').textContent = detail;
    $('toasts').append(el);
    setTimeout(() => el.remove(), 6000);
}

async function api(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) },
    });
    const body = await response.json().catch(() => null);
    if (response.status === 401 || response.status === 419) {
        window.location.href = '/';
    }
    if (!response.ok) {
        throw new Error(body?.message || `Request failed (${response.status})`);
    }
    return body;
}

async function recordEvent(type, fields = {}, snapshot = null) {
    const form = new FormData();
    form.append('type', type);
    form.append('source', fields.source ?? state.source);
    if (fields.previous_source) form.append('previous_source', fields.previous_source);
    if (snapshot) form.append('snapshot', snapshot, 'snapshot.jpg');
    try {
        await api(cfg.routes.events, { method: 'POST', body: form });
        scheduleLogRefresh(300);
    } catch (e) {
        console.warn('Could not record event', type, e);
    }
}

// ---------------------------------------------------------------- camera
const viewer = $('viewer');
const streamImg = $('stream');
const webcam = $('webcam');
const placeholderText = $('placeholderText');
const streamError = $('streamError');

function showError(message) {
    streamError.textContent = message;
    streamError.hidden = !message;
}

function mediaEl() {
    return state.source === 'laptop' ? webcam : streamImg;
}

async function cameraOn() {
    showError('');
    if (state.source === 'laptop') {
        if (!navigator.mediaDevices?.getUserMedia) {
            showError('The browser blocks camera access on this address. Open the dashboard via http://localhost or http://127.0.0.1 (or HTTPS).');
            return false;
        }
        try {
            state.mediaStream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
        } catch (e) {
            showError(e.name === 'NotAllowedError'
                ? 'Camera permission was denied. Allow camera access in the browser address bar and try again.'
                : `Could not open the laptop camera (${e.name}).`);
            return false;
        }
        webcam.srcObject = state.mediaStream;
        await webcam.play().catch(() => {});
        webcam.hidden = false;
        detector.start(webcam);
        setMotion('laptop', false);
    } else {
        streamImg.crossOrigin = 'anonymous'; // ESP32 sends Access-Control-Allow-Origin: *, keeps snapshots possible
        streamImg.src = cfg.streamUrl + (cfg.streamUrl.includes('?') ? '&' : '?') + 't=' + Date.now();
        streamImg.hidden = false;
    }
    $('placeholder').hidden = true;
    $('hud').hidden = false;
    $('hudSource').textContent = ' ' + SOURCES[state.source];
    return true;
}

function cameraOff() {
    detector.stop();
    if (state.mediaStream) {
        state.mediaStream.getTracks().forEach((t) => t.stop());
        state.mediaStream = null;
    }
    webcam.srcObject = null;
    webcam.hidden = true;
    streamImg.removeAttribute('src');
    streamImg.hidden = true;
    $('placeholder').hidden = false;
    $('hud').hidden = true;
}

function renderCameraButton() {
    const btn = $('cameraToggle');
    btn.querySelector('span').textContent = state.cameraOn ? 'Camera Off' : 'Camera On';
    btn.classList.toggle('btn--danger', state.cameraOn);
    $('snapshotBtn').disabled = !state.cameraOn;
    placeholderText.textContent = state.source === 'laptop'
        ? 'Press “Camera On” and allow camera access to use the laptop webcam.'
        : 'Press “Camera On” to open the ESP32-CAM stream.';
}

$('cameraToggle').addEventListener('click', async () => {
    if (state.cameraOn) {
        cameraOff();
        state.cameraOn = false;
        recordEvent('camera_off');
    } else {
        state.cameraOn = await cameraOn();
        if (state.cameraOn) recordEvent('camera_on');
    }
    renderCameraButton();
});

streamImg.addEventListener('error', () => {
    if (state.source !== 'esp32' || !state.cameraOn) return;
    cameraOff();
    state.cameraOn = false;
    renderCameraButton();
    showError('The ESP32-CAM stream could not be opened. Check that the board is powered and CAMERA_STREAM_URL is correct — or switch to the laptop camera.');
    $('esp32Hint').hidden = false;
});

/** Captures the current frame as a JPEG blob (max 800px wide). */
function captureFrame() {
    const el = mediaEl();
    const w = el.videoWidth || el.naturalWidth;
    const h = el.videoHeight || el.naturalHeight;
    if (!w || !h) return Promise.resolve(null);
    const scale = Math.min(1, 800 / w);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(w * scale);
    canvas.height = Math.round(h * scale);
    try {
        canvas.getContext('2d').drawImage(el, 0, 0, canvas.width, canvas.height);
        return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
    } catch {
        return Promise.resolve(null); // tainted canvas (stream without CORS)
    }
}

$('snapshotBtn').addEventListener('click', async () => {
    const blob = await captureFrame();
    if (!blob) {
        toast('Snapshot unavailable', 'The camera did not provide a frame yet.');
        return;
    }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `seccam-${new Date().toISOString().replace(/[:.]/g, '-')}.jpg`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
});

setInterval(() => {
    $('hudClock').textContent = new Date().toLocaleString();
}, 1000);

// ---------------------------------------------------------------- source switching
function applySource() {
    const laptop = state.source === 'laptop';
    $(laptop ? 'sourceLaptop' : 'sourceEsp32').checked = true;
    $('sourceInfo').textContent = laptop
        ? 'Webcam in this browser · digital pan/tilt · browser motion detection'
        : 'ESP32-CAM stream · servo pan/tilt · PIR sensor';
    $('zoomBox').hidden = !laptop;
    $('sensitivityBox').hidden = !laptop;
    $('modeNote').textContent = laptop
        ? 'Laptop mode: the webcam cannot rotate, so pan/tilt moves a zoomed view across the image (digital PTZ).'
        : 'ESP32 mode: commands are sent to the pan/tilt servos over MQTT.';
    $('esp32Hint').hidden = laptop || state.deviceOnline !== false;
    renderDeviceChip();
    renderMotion();
    applyPtz();
    renderCameraButton();
}

async function switchSource(next) {
    if (next === state.source) return;
    const previous = state.source;
    const wasOn = state.cameraOn;
    if (wasOn) cameraOff();
    state.cameraOn = false;
    state.source = next;
    store.set('seccam.source', next);
    showError('');
    applySource();
    recordEvent('source_changed', { source: next, previous_source: previous });
    if (wasOn) {
        state.cameraOn = await cameraOn();
        renderCameraButton();
    }
}

document.querySelectorAll('input[name="source"]').forEach((input) => {
    input.addEventListener('change', () => switchSource(input.value));
});
$('useLaptopBtn').addEventListener('click', () => switchSource('laptop'));

// ---------------------------------------------------------------- motion
const chart = new Chart($('motionChart'), {
    type: 'line',
    data: { labels: [], datasets: [{ data: [], stepped: true, fill: true, borderWidth: 2, pointRadius: 0, borderColor: '#ffe500', backgroundColor: 'rgba(255,229,0,.18)' }] },
    options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: {
            x: { display: false },
            y: { min: 0, max: 1, ticks: { stepSize: 1, color: '#a7c0d3', callback: (v) => (v ? 'motion' : 'clear') }, grid: { color: 'rgba(167,192,211,.12)' } },
        },
    },
});

function pushChart(value) {
    const ds = chart.data.datasets[0];
    chart.data.labels.push('');
    ds.data.push(value);
    if (ds.data.length > 60) { ds.data.shift(); chart.data.labels.shift(); }
    chart.update('none');
}
setInterval(() => {
    const current = state.motion[state.source];
    if (current !== null) pushChart(current ? 1 : 0);
}, 1000);

function renderMotion() {
    const value = state.motion[state.source];
    const ring = $('motionRing');
    ring.classList.toggle('is-motion', value === true);
    ring.classList.toggle('is-idle', value === null);
    viewer.classList.toggle('is-motion', value === true && state.cameraOn);
    if (value === null) {
        $('motionState').textContent = 'No data';
        $('motionDetail').textContent = state.source === 'laptop'
            ? 'Turn the camera on to start detecting motion.'
            : 'Waiting for the PIR sensor…';
    } else {
        $('motionState').textContent = value ? 'Motion!' : 'All clear';
        $('motionDetail').textContent = `${SOURCES[state.source]} · ${new Date().toLocaleTimeString()}`;
    }
}

function setMotion(source, active) {
    if (state.motion[source] === active) return;
    const wasKnown = state.motion[source] !== null;
    state.motion[source] = active;
    if (source === state.source) renderMotion();
    if (active && (wasKnown || source === 'laptop')) {
        alertMotion(source);
    }
}

function alertMotion(source) {
    toast('Motion detected!', `${SOURCES[source]} · ${new Date().toLocaleTimeString()}`, 'danger');
    if ('Notification' in window && Notification.permission === 'granted') {
        try {
            new Notification('Smart Security Cam: motion detected', { body: `${SOURCES[source]} at ${new Date().toLocaleTimeString()}`, icon: '/img/logo.png', tag: 'seccam-motion' });
        } catch { /* some browsers only allow notifications from a service worker */ }
    }
}

const detector = new MotionDetector({
    sensitivity: Number(store.get('seccam.sensitivity', 6)),
    onChange: async (active) => {
        setMotion('laptop', active);
        if (active) {
            const snapshot = await captureFrame();
            recordEvent('motion_started', { source: 'laptop' }, snapshot);
        } else if (state.cameraOn) {
            recordEvent('motion_stopped', { source: 'laptop' });
        }
    },
});

const sensitivity = $('sensitivity');
sensitivity.value = store.get('seccam.sensitivity', 6);
$('sensitivityValue').textContent = sensitivity.value;
sensitivity.addEventListener('input', () => {
    $('sensitivityValue').textContent = sensitivity.value;
    detector.setSensitivity(sensitivity.value);
    store.set('seccam.sensitivity', sensitivity.value);
    paintRange(sensitivity);
});

function renderAlertsButton() {
    const btn = $('alertsBtn');
    if (!('Notification' in window)) { btn.hidden = true; return; }
    const granted = Notification.permission === 'granted';
    btn.querySelector('span').textContent = granted ? 'Alerts on' : (Notification.permission === 'denied' ? 'Alerts blocked' : 'Enable alerts');
    btn.disabled = granted || Notification.permission === 'denied';
}
$('alertsBtn').addEventListener('click', async () => {
    await Notification.requestPermission();
    renderAlertsButton();
});

// ---------------------------------------------------------------- movement control
const sliders = { pan: $('pan'), tilt: $('tilt') };
const outputs = { pan: $('panValue'), tilt: $('tiltValue') };
const lastSent = { pan: { at: 0, value: null }, tilt: { at: 0, value: null } };
const SEND_INTERVAL_MS = 80;

function paintRange(input) {
    const pct = ((input.value - input.min) / (input.max - input.min)) * 100;
    input.style.setProperty('--fill', `${pct}%`);
}

function applyPtz() {
    const zoom = Number($('zoom').value);
    const panPct = (sliders.pan.value / 180) * 100;
    const tiltPct = 100 - ((sliders.tilt.value - 40) / 110) * 100;
    $('zoomValue').textContent = `${zoom.toFixed(1)}×`;
    if (state.source === 'laptop') {
        webcam.style.transformOrigin = `${panPct}% ${tiltPct}%`;
        webcam.style.transform = `scale(${zoom})`;
    } else {
        webcam.style.transform = '';
    }
    $('hudPtz').textContent = `PAN ${sliders.pan.value}° · TILT ${sliders.tilt.value}°` + (state.source === 'laptop' ? ` · ${zoom.toFixed(1)}×` : '');
}

function sendAxis(axis, force = false) {
    const value = sliders[axis].value;
    if (state.source !== 'esp32') return;
    if (!force && lastSent[axis].value === value) return;
    lastSent[axis] = { at: Date.now(), value };
    publish(cfg.mqtt.topics[axis], value);
}

function setAxis(axis, value, { send = true } = {}) {
    const input = sliders[axis];
    const clamped = Math.min(Number(input.max), Math.max(Number(input.min), Math.round(value)));
    input.value = clamped;
    outputs[axis].textContent = clamped;
    paintRange(input);
    applyPtz();
    if (send) sendAxis(axis);
}

Object.keys(sliders).forEach((axis) => {
    const input = sliders[axis];
    input.addEventListener('input', () => {
        outputs[axis].textContent = input.value;
        paintRange(input);
        applyPtz();
        // throttle while dragging so the broker/servo is not flooded
        if (Date.now() - lastSent[axis].at >= SEND_INTERVAL_MS) sendAxis(axis);
    });
    input.addEventListener('change', () => sendAxis(axis));
    paintRange(input);
});

$('zoom').addEventListener('input', () => { paintRange($('zoom')); applyPtz(); });
paintRange($('zoom'));

document.querySelectorAll('[data-step]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const [axis, delta] = btn.dataset.step.split(':');
        setAxis(axis, Number(sliders[axis].value) + Number(delta));
    });
});
$('center').addEventListener('click', () => {
    setAxis('pan', DEFAULT_POS.pan);
    setAxis('tilt', DEFAULT_POS.tilt);
});

document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select')) return;
    const map = { ArrowLeft: ['pan', -5], ArrowRight: ['pan', 5], ArrowUp: ['tilt', 5], ArrowDown: ['tilt', -5] };
    if (map[e.key]) {
        e.preventDefault();
        const [axis, delta] = map[e.key];
        setAxis(axis, Number(sliders[axis].value) + delta);
    }
});

// ---------------------------------------------------------------- MQTT
const client = new Paho.MQTT.Client(cfg.mqtt.host, Number(cfg.mqtt.port), cfg.mqtt.path, 'seccam-web-' + Math.random().toString(16).slice(2, 10));
let reconnectTimer = null;
let esp32HintTimer = null;

function renderDeviceChip() {
    const chip = $('deviceStatus');
    if (state.source === 'laptop') {
        setChip(chip, 'Laptop camera', 'info');
    } else if (state.deviceOnline === true) {
        setChip(chip, 'ESP32: online', 'ok');
    } else if (state.deviceOnline === false) {
        setChip(chip, 'ESP32: offline', 'bad');
    } else {
        setChip(chip, 'ESP32: unknown', 'wait');
    }
}

function scheduleReconnect() {
    if (reconnectTimer === null) {
        reconnectTimer = setTimeout(() => { reconnectTimer = null; connect(); }, 3000);
    }
}

client.onConnectionLost = () => {
    setChip($('mqttStatus'), 'MQTT: disconnected', 'bad');
    scheduleReconnect();
};

client.onMessageArrived = (message) => {
    const topic = message.destinationName;
    const payload = message.payloadString.trim();
    const t = cfg.mqtt.topics;
    if (topic === t.motion) {
        setMotion('esp32', payload === '1');
        if (!message.retained) scheduleLogRefresh(1500); // mqtt:listen stores it
    } else if (topic === t.status) {
        state.deviceOnline = payload === 'online';
        clearTimeout(esp32HintTimer);
        $('esp32Hint').hidden = state.source === 'laptop' || state.deviceOnline;
        renderDeviceChip();
        if (!message.retained) scheduleLogRefresh(1500);
    } else if ((topic === t.pan || topic === t.tilt) && state.source === 'esp32') {
        // retained position keeps the controls in sync across tabs
        const axis = topic === t.pan ? 'pan' : 'tilt';
        if (document.activeElement !== sliders[axis] && /^\d{1,3}$/.test(payload)) {
            setAxis(axis, Number(payload), { send: false });
            lastSent[axis].value = sliders[axis].value;
        }
    }
};

function connect() {
    const options = {
        useSSL: cfg.mqtt.useSSL,
        timeout: 5,
        onSuccess: () => {
            setChip($('mqttStatus'), 'MQTT: connected', 'ok');
            Object.values(cfg.mqtt.topics).forEach((topic) => client.subscribe(topic));
            // no retained status after a few seconds = no ESP32 has ever reported
            clearTimeout(esp32HintTimer);
            esp32HintTimer = setTimeout(() => {
                if (state.deviceOnline === null && state.source === 'esp32') $('esp32Hint').hidden = false;
            }, 4000);
        },
        onFailure: () => {
            setChip($('mqttStatus'), 'MQTT: offline', 'bad');
            if (state.source === 'esp32') $('esp32Hint').hidden = false;
            scheduleReconnect();
        },
    };
    if (cfg.mqtt.username) {
        options.userName = cfg.mqtt.username;
        options.password = cfg.mqtt.password || '';
    }
    setChip($('mqttStatus'), 'MQTT: connecting', 'wait');
    try {
        client.connect(options);
    } catch {
        scheduleReconnect();
    }
}

function publish(topic, value) {
    if (!client.isConnected()) return;
    const message = new Paho.MQTT.Message(String(value));
    message.destinationName = topic;
    message.qos = 0;
    message.retained = true; // device picks up the last position after reboot
    client.send(message);
}

// ---------------------------------------------------------------- activity log
const ICONS = {
    motion_started: 'i-motion', motion_stopped: 'i-shield', device_online: 'i-chip', device_offline: 'i-chip',
    camera_on: 'i-power', camera_off: 'i-power', source_changed: 'i-camera',
};
let logTimer = null;

function scheduleLogRefresh(delay) {
    clearTimeout(logTimer);
    logTimer = setTimeout(() => loadLog(), delay);
}

function renderEvent(event) {
    const li = document.createElement('li');
    li.dataset.kind = event.type;
    li.innerHTML = '<span class="ico"><svg><use/></svg></span><div><div class="title"></div><div class="meta"></div></div>';
    li.querySelector('use').setAttribute('href', '#' + (ICONS[event.type] || 'i-list'));
    li.querySelector('.title').textContent = event.label;

    const details = [new Date(event.occurred_at).toLocaleString(), event.source_label];
    if (event.payload?.duration_seconds !== undefined) details.push(`${event.payload.duration_seconds}s`);
    if (event.payload?.from) details.push(`${SOURCES[event.payload.from] ?? event.payload.from} → ${SOURCES[event.payload.to] ?? event.payload.to}`);
    if (event.user) details.push(`by ${event.user.username}`);
    li.querySelector('.meta').textContent = details.join(' · ');

    if (event.snapshot_url) {
        const a = document.createElement('a');
        a.className = 'thumb';
        a.href = event.snapshot_url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.title = 'Open snapshot';
        const img = document.createElement('img');
        img.src = event.snapshot_url;
        img.alt = `Snapshot: ${event.label}`;
        img.loading = 'lazy';
        a.append(img);
        li.append(a);
    }
    return li;
}

async function loadLog({ append = false } = {}) {
    const page = append ? state.logPage + 1 : 1;
    const params = new URLSearchParams({ per_page: 12, page });
    if (state.logCategory) params.set('category', state.logCategory);
    try {
        const { data, meta } = await api(`${cfg.routes.events}?${params}`);
        const list = $('log');
        if (!append) list.replaceChildren();
        data.forEach((event) => list.append(renderEvent(event)));
        if (!list.children.length) {
            const empty = document.createElement('li');
            empty.className = 'log-empty';
            empty.style.display = 'block';
            empty.textContent = 'No events yet. Motion, camera and device changes will appear here.';
            list.append(empty);
        }
        state.logPage = meta.page;
        $('logMore').hidden = meta.page >= meta.total_pages;
        $('statToday').textContent = meta.motion_today;
        $('statLast').textContent = timeAgo(meta.last_motion_at);
    } catch (e) {
        console.warn('Could not load events', e);
    }
}

document.querySelectorAll('.log-filters button').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.log-filters button').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
        state.logCategory = btn.dataset.category;
        loadLog();
    });
});
$('logMore').addEventListener('click', () => loadLog({ append: true }));
setInterval(() => { if (!document.hidden) loadLog(); }, 20000);

// ---------------------------------------------------------------- boot
applySource();
renderAlertsButton();
renderDeviceChip();
connect();
loadLog();
