# Smart Security Cam

[![CI](https://github.com/FazryJavier/IoTCamSecurity/actions/workflows/ci.yml/badge.svg)](https://github.com/FazryJavier/IoTCamSecurity/actions/workflows/ci.yml)

A home security camera you control from the browser. An **ESP32-CAM** streams live video, two servos turn the camera (pan/tilt), and a **PIR sensor** detects movement. A **Laravel** dashboard shows the stream, moves the camera, raises alerts, and keeps a log of security events with snapshots.

No ESP32-CAM yet? The dashboard also works with your **laptop webcam**, with digital pan/tilt and motion detection in the browser.

**Features**

- **Video streaming:** live MJPEG stream from the ESP32-CAM, or the laptop webcam.
- **Movement control:** pan/tilt with a D-pad, sliders, or arrow keys. Commands go to the servos over MQTT; in laptop mode the dashboard zooms and moves the view digitally.
- **Motion detector:** PIR sensor (ESP32) or frame differencing (laptop), with on-screen and browser notifications.
- **Activity log:** motion start/stop with a snapshot and duration, device online/offline, camera on/off, and camera source changes, stored in the database.
- **Device status:** the ESP32 reports online/offline through an MQTT last-will message.
- **Hardware simulator:** a fake ESP32 for testing the whole flow without hardware.

---

## Contents

1. [How it works](#how-it-works)
2. [Requirements](#requirements)
3. [Quick start (no hardware)](#quick-start-no-hardware)
4. [Setting up the ESP32-CAM](#setting-up-the-esp32-cam)
5. [Using the dashboard](#using-the-dashboard)
6. [Configuration](#configuration)
7. [Event storage](#event-storage)
8. [MQTT topics](#mqtt-topics)
9. [HTTP endpoints](#http-endpoints)
10. [Running tests](#running-tests)
11. [Troubleshooting](#troubleshooting)
12. [Project structure](#project-structure)
13. [Security notes](#security-notes)
14. [Credits](#credits)

---

## How it works

```
                          ┌──────────────────────── your computer ────────────────────────┐
 Browser ── HTTP :8000 ───▶ Laravel  (php artisan serve)   login, dashboard, /events       │
    │                     │     ▲ events from laptop-camera mode    ▲                       │
    │                     │     │                                    │ php artisan mqtt:listen
    └── WebSocket :8083 ──▶ MQTT broker (npm run broker) ────────────┘ stores ESP32 events │
                          │     :1883 TCP ◀───────────────────────────────┐                 │
                          └───────────────────────────────────────────────┼─────────────────┘
 Browser ◀── MJPEG http://<esp32-ip>/mjpeg/1 ── ESP32-CAM ── MQTT :1883 ──┘   (same WiFi)
 Laravel ◀── JPEG  http://<esp32-ip>/jpg  (snapshot when the PIR fires)
```

| Process | Port | Started by | Role |
|---|---|---|---|
| Laravel web app | 8000 | `php artisan serve` | Login, dashboard, event API |
| MQTT broker | 1883 (TCP), 8083 (WebSocket) | `npm run broker` | Messages between ESP32 and browser |
| Event ingest worker | – | `php artisan mqtt:listen` | Saves ESP32 motion/status events, even when no dashboard is open |
| Database | MySQL 3306 or SQLite file | MySQL server / none | Users and the security event log |
| ESP32-CAM | 80 | the board itself | Video stream, servos, PIR |

`composer dev` starts the web app, the broker, and the ingest worker together.

---

## Requirements

**Software**

- PHP **8.2+** with the `pdo_mysql` and/or `pdo_sqlite`, `fileinfo` and `gd` extensions enabled in `php.ini`.
- [Composer](https://getcomposer.org/) 2.
- [Node.js](https://nodejs.org/) **20+** (runs the bundled MQTT broker and the simulator).
- MySQL/MariaDB (for example [Laragon](https://laragon.org/) or XAMPP), **or** SQLite (no server needed).
- [Arduino IDE](https://www.arduino.cc/en/software) 2.x (only for flashing the ESP32-CAM).

**Hardware** (only for the ESP32 mode)

| Part | Notes |
|---|---|
| ESP32-CAM (AI-Thinker) with OV2640 camera | With PSRAM |
| USB programmer | ESP32-CAM-MB shield, or an FTDI/CP2102 USB-to-serial adapter (5 V) |
| 2 × SG90 / MG90S servos + pan/tilt bracket | Pan (left-right) and tilt (up-down) |
| HC-SR501 PIR motion sensor | |
| 5 V power supply, ≥ 1 A | Separate supply for the servos |
| Jumper wires, breadboard | |

---

## Quick start (no hardware)

```bash
git clone https://github.com/FazryJavier/IoTCamSecurity.git
cd IoTCamSecurity
composer install
composer setup
```

`composer setup` creates `.env`, generates `APP_KEY`, creates the SQLite file if needed, runs the migrations, creates the login account, and installs the Node packages. **The generated password is printed in the terminal** (`Generated password: …`). To choose your own, set `SEED_USER_PASSWORD` in `.env` before running it.

> Using MySQL instead of SQLite? Before `composer setup`, copy `.env.example` to `.env`, set `DB_CONNECTION=mysql`, uncomment the `DB_*` lines, and create the database (for example `iot-security-cam`).

Start everything:

```bash
composer dev
```

Open **http://127.0.0.1:8000** and log in with `admin@seccam.test` (or your `SEED_USER_EMAIL`). Then try one of these:

- **Laptop camera:** choose **Laptop Cam**, press **Camera On**, and allow camera access. Move in front of the camera and watch the Motion Detector and Activity Log.
- **Simulated ESP32:** stop `composer dev` and run `composer dev:sim` instead. A fake ESP32 comes online, sends random motion, and prints every pan/tilt command it receives.

---

## Setting up the ESP32-CAM

The firmware is in [`esp32_camera_mjpeg_multiclient/`](esp32_camera_mjpeg_multiclient). On one board it provides:

- the MJPEG stream (`/mjpeg/1`, up to 10 viewers) and a still image (`/jpg`)
- pan/tilt servos driven by MQTT messages
- the PIR sensor, published over MQTT
- an online/offline status (MQTT last will)

### 1. Wire it up

| Component | ESP32-CAM pin |
|---|---|
| Pan servo, signal (orange/yellow) | **GPIO 14** |
| Tilt servo, signal (orange/yellow) | **GPIO 15** |
| PIR sensor OUT | **GPIO 13** |
| Servo +5 V (red) | **External 5 V supply** |
| PIR VCC | 5 V |
| All GND (servos, PIR, external supply) | ESP32-CAM GND (**common ground**) |

> Power the servos from a separate 5 V supply and connect all grounds together. Servos powered from the ESP32-CAM's 5 V pin cause brown-outs and random reboots while streaming. Do not use an SD card: GPIO 13/14/15 are shared with the SD slot.

### 2. Install the Arduino tooling

1. Arduino IDE → **File → Preferences → Additional boards manager URLs**:
   `https://espressif.github.io/arduino-esp32/package_esp32_index.json`
2. **Tools → Board → Boards Manager** → install **esp32 by Espressif Systems**. Version **2.0.17** is the safest choice for this camera code. If a 3.x core fails to compile, switch to 2.0.17.
3. **Tools → Manage Libraries** → install **PubSubClient** by Nick O'Leary.

### 3. Add your WiFi and MQTT settings

```bash
cp esp32_camera_mjpeg_multiclient/home_wifi_multi.example.h esp32_camera_mjpeg_multiclient/home_wifi_multi.h
```

Edit `home_wifi_multi.h`:

```c
#define SSID1 "your-wifi-name"
#define PWD1  "your-wifi-password"

#define MQTT_HOST "192.168.1.10"   // LAN IP of the computer running the broker — NOT "localhost"
#define MQTT_PORT 1883
#define MQTT_USER ""               // only if MQTT_BROKER_USERNAME is set in .env
#define MQTT_PASS ""
#define MQTT_TOPIC_PREFIX "seccam" // must match MQTT_TOPIC_PREFIX in .env
```

Find your computer's LAN IP with `ipconfig` (Windows) or `ip a` / `ifconfig` (Linux/macOS). The ESP32 and the computer must be on the **same WiFi network**. `home_wifi_multi.h` is git-ignored, so your password never ends up in the repository.

### 4. Flash the board

1. Open `esp32_camera_mjpeg_multiclient/esp32_camera_mjpeg_multiclient.ino`.
2. **Tools → Board → AI Thinker ESP32-CAM**. Leave PSRAM enabled.
3. Connect the programmer:
   - **ESP32-CAM-MB shield:** plug in via USB.
   - **FTDI adapter:** set it to 5 V and connect FTDI TX → U0R, FTDI RX → U0T, 5 V → 5 V, GND → GND. Then connect **GPIO 0 to GND** to enter flash mode and press **RST**.
4. Select the COM port and click **Upload**.
5. FTDI only: remove the GPIO 0 → GND wire and press **RST**.

### 5. Connect it to the dashboard

Open **Serial Monitor** at **115200 baud**. You should see:

```
Connecting to WiFi....WiFi connected
Stream Link: http://192.168.1.42/mjpeg/1
Connecting to MQTT 192.168.1.10:1883 ... connected
```

Put the stream link into `.env`:

```dotenv
CAMERA_STREAM_URL=http://192.168.1.42/mjpeg/1
CAMERA_DEFAULT_SOURCE=esp32
```

Snapshots automatically use `http://192.168.1.42/jpg`. Restart `composer dev`, open the dashboard, select **ESP32-CAM**, and press **Camera On**. The top bar should show **MQTT: connected** and **ESP32: online**.

### 6. Allow the ESP32 through the firewall

The ESP32 connects to your computer on TCP **1883**. On Windows, allow Node.js on **Private networks** when the firewall prompt appears the first time you run `npm run broker`, or add a rule in an **Administrator** PowerShell:

```powershell
New-NetFirewallRule -DisplayName "MQTT 1883 (Smart Security Cam)" -Direction Inbound -Protocol TCP -LocalPort 1883 -Action Allow -Profile Private
```

---

## Using the dashboard

| Panel | What you can do |
|---|---|
| **Video Streaming** | Switch between **ESP32-CAM** and **Laptop Cam**, turn the camera on/off, download a snapshot, enable browser alerts. If no ESP32 reports in, a **Use laptop cam** button appears. |
| **Motion Detector** | Live status (All clear / Motion!), motion count today, time of the last motion, a timeline chart, and a sensitivity slider (laptop mode). |
| **Movement Control** | D-pad, sliders, or the keyboard arrow keys; the centre button returns to pan 90° / tilt 95°. ESP32 mode moves the servos; laptop mode moves a zoomed view (digital pan/tilt/zoom). |
| **Activity Log** | Stored events with snapshot thumbnails, filtered by Motion / Camera / Device. |

Notes on laptop mode:

- Browsers only allow camera access on **`http://localhost`, `http://127.0.0.1`, or HTTPS**. If you open the dashboard by LAN IP (for example from a phone), camera access is blocked.
- Motion is detected in the browser, so laptop-mode events are recorded **only while the dashboard is open**. ESP32 events are recorded by `mqtt:listen` at all times.

---

## Configuration

All settings are in `.env` (see [`.env.example`](.env.example)).

| Key | Default | Description |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Jakarta` | Time zone used for "motion today" and timestamps |
| `DB_CONNECTION` | `sqlite` | `sqlite` or `mysql` (then set `DB_HOST`, `DB_DATABASE`, …) |
| `CAMERA_STREAM_URL` | `http://192.168.38.184/mjpeg/1` | ESP32-CAM stream (from the Serial Monitor) |
| `CAMERA_SNAPSHOT_URL` | derived (`…/jpg`) | Still image fetched when the PIR fires |
| `CAMERA_DEFAULT_SOURCE` | `esp32` | Pre-selected source for new browsers: `esp32` or `laptop` |
| `MQTT_WS_HOST` / `MQTT_WS_PORT` / `MQTT_WS_PATH` | `localhost` / `8083` / `/mqtt` | Broker address **as seen from the browser**. Use the computer's LAN IP if you open the dashboard from another device |
| `MQTT_WS_USERNAME` / `MQTT_WS_PASSWORD` | empty | Browser login to the broker (if auth is enabled) |
| `MQTT_TOPIC_PREFIX` | `seccam` | Must match the sketch |
| `MQTT_BROKER_TCP_PORT` / `MQTT_BROKER_WS_PORT` | `1883` / `8083` | Ports of the bundled broker |
| `MQTT_BROKER_USERNAME` / `MQTT_BROKER_PASSWORD` | empty | Enable login on the bundled broker |
| `MQTT_LISTENER_HOST` | `127.0.0.1` | Broker address used by `mqtt:listen` |
| `EVENTS_RETENTION_DAYS` | `30` | Events and snapshots older than this are deleted |
| `SEED_USER_EMAIL` / `SEED_USER_PASSWORD` | `admin@seccam.test` / empty | Account created by `php artisan db:seed` (empty = random password, printed once) |

### Using another broker (Mosquitto / EMQX)

Run only one broker at a time, because they use the same ports. If you use another broker, start the app with `php artisan serve` and `php artisan mqtt:listen` instead of `composer dev`.

- **Mosquitto** (`mosquitto.conf`):
  ```
  listener 1883
  protocol mqtt

  listener 8083
  protocol websockets

  allow_anonymous true
  ```
- **EMQX** (Docker): `docker run -d --name emqx -p 1883:1883 -p 8083:8083 -p 18083:18083 emqx/emqx:5.8.6`

---

## Event storage

The `device_events` table stores **security events**, not every change:

| Type | Source | Extra data |
|---|---|---|
| `motion_started` | PIR (ESP32) or browser detection (laptop) | Snapshot JPEG |
| `motion_stopped` | same | `duration_seconds` |
| `device_online` / `device_offline` | ESP32 MQTT status | Recorded only when it changes |
| `camera_on` / `camera_off` | Dashboard | User who pressed it |
| `source_changed` | Dashboard | `{from, to}` and the user |

- **Servo positions are not stored.** They are high-frequency control commands, and the latest position is kept as a retained MQTT message.
- Duplicate and retained (replayed) MQTT messages are ignored, so one movement produces one row.
- Snapshots are stored privately in `storage/app/private/snapshots/` and served only to logged-in users.
- `php artisan model:prune` deletes expired events together with their snapshots. It is scheduled daily: run `php artisan schedule:work` in development, or a cron entry for `php artisan schedule:run` on a server.

---

## MQTT topics

With the default prefix `seccam`:

| Topic | Direction | Payload |
|---|---|---|
| `seccam/servo/pan` | dashboard → ESP32 | `0`–`180` (retained) |
| `seccam/servo/tilt` | dashboard → ESP32 | `40`–`150` (retained) |
| `seccam/motion` | ESP32 → dashboard, ingest | `1` motion / `0` clear (retained) |
| `seccam/status` | ESP32 → dashboard, ingest | `online` / `offline` (last will, retained) |

The bundled broker rejects publishing and subscribing outside `seccam/#`.

---

## HTTP endpoints

All endpoints need a logged-in session. JSON responses use the envelope `{code, success, message, data, errors, meta}`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/events?category=motion\|camera\|device&page=&per_page=` | Event list. `meta` holds `page`, `per_page`, `total`, `total_pages`, `motion_today`, `last_motion_at` |
| `POST` | `/events` | Record a dashboard event: `type`, `source`, `previous_source` (required for `source_changed`), `snapshot` (JPEG ≤ 1 MB, `motion_started` only). ESP32 motion and device status are rejected (422) because they may only come from MQTT |
| `GET` | `/events/{id}/snapshot` | Snapshot image |
| `GET` | `/api/users` | User list (Sanctum token required) |
| `GET` | `/up` | Health check |

---

## Running tests

```bash
composer test   # Laravel: auth, dashboard, event API, MQTT ingest, snapshots, retention
npm test        # browser motion detector + MQTT broker integration
```

GitHub Actions ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs on every push and pull request:

- Laravel tests on PHP 8.2 and 8.4
- Node tests (motion detector and MQTT broker)
- A compile check of the ESP32-CAM firmware (arduino-esp32 2.0.17 + PubSubClient)

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `could not find driver` | Enable `extension=pdo_mysql` and/or `extension=pdo_sqlite` in `php.ini` (`php --ini` shows which file) |
| `Unknown database '…'` | Create the MySQL database, then `php artisan migrate` |
| Top bar shows **MQTT: offline** | Is `npm run broker` running? Is another broker already using port 8083? |
| **ESP32: offline / unknown** | Serial Monitor shows `MQTT … failed`: check `MQTT_HOST` (computer's LAN IP), same WiFi, firewall port 1883 |
| `EADDRINUSE` from the broker | Another MQTT broker (Mosquitto/EMQX) is already running |
| Stream does not load | `CAMERA_STREAM_URL` must match the Serial Monitor; try opening the URL directly in a browser |
| ESP32 events missing from the log | `php artisan mqtt:listen` must be running (included in `composer dev`) |
| Board reboots / `Brownout detector was triggered` | Power the servos from a separate 5 V supply with a common ground |
| Servos jitter or do not move | Signal wires on GPIO 14/15, common ground, enough current |
| `Camera init failed` | Check the camera ribbon cable and that **AI Thinker ESP32-CAM** is selected |
| Laptop camera blocked | Allow camera access in the address bar; open the dashboard via `localhost`/`127.0.0.1`, not a LAN IP |

---

## Project structure

```
app/
  Console/Commands/MqttListen.php      MQTT → database ingest worker
  Enums/                               DeviceEventType, CameraSource
  Http/Controllers/                    Login, dashboard, device events, users API
  Models/DeviceEvent.php               Event model (+ retention pruning)
  Services/DeviceEventRecorder.php     Event rules: dedup, durations, snapshots
public/
  css/seccam.css                       Dashboard theme
  js/dashboard.js                      Camera sources, MQTT, controls, activity log
  js/motion-detector.js                Browser motion detection (frame differencing)
resources/views/                       Login page, dashboard, layout, icons
scripts/
  mqtt-broker.mjs                      Bundled MQTT broker (TCP + WebSocket)
  esp32-simulator.mjs                  Fake ESP32 for testing without hardware
esp32_camera_mjpeg_multiclient/        ESP32-CAM firmware used by the dashboard
esp32cam/CameraWebServer/              Espressif camera example (reference only)
Servo/, PIR/                           Standalone hardware test sketches
tests/                                 PHPUnit (Feature) and Node (js, broker) tests
```

---

## Security notes

- Keep secrets out of git: `.env` and `esp32_camera_mjpeg_multiclient/home_wifi_multi.h` are ignored.
- The bundled broker is anonymous by default, which is fine on a trusted home or lab network only. For anything else, set `MQTT_BROKER_USERNAME`/`MQTT_BROKER_PASSWORD` and use the same credentials in the sketch and in `MQTT_WS_*`. The browser can see the `MQTT_WS_*` credentials, so use a broker account that is limited to the `seccam/#` topics.
- The ESP32 stream and the MQTT broker are plain HTTP/MQTT. Do not expose them to the internet without TLS and authentication (for example a VPN or a reverse proxy).

---

## Credits

- ESP32 multi-client MJPEG streaming is based on [arkhipenko/esp32-cam-mjpeg-multiclient](https://github.com/arkhipenko/esp32-cam-mjpeg-multiclient) (see `esp32_camera_mjpeg_multiclient/LICENSE.txt`).
- `esp32cam/CameraWebServer` is the Espressif ESP32 camera example.
- Built with [Laravel](https://laravel.com), [Aedes](https://github.com/moscajs/aedes), [Paho MQTT](https://www.eclipse.org/paho/), [Chart.js](https://www.chartjs.org) and [php-mqtt/client](https://github.com/php-mqtt/client).
