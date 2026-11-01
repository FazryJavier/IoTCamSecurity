@extends('layouts.app')

@section('title', 'Dashboard · Smart Security Cam')

@section('content')
<header class="topbar">
    <a class="topbar__brand" href="{{ route('home') }}">
        @include('partials.emblem', ['class' => 'emblem--sm'])
        <span class="display">Smart <span>Security</span> Cam</span>
    </a>
    <div class="topbar__status" aria-live="polite">
        <span id="mqttStatus" class="chip" data-state="wait">MQTT: connecting</span>
        <span id="deviceStatus" class="chip">ESP32: unknown</span>
    </div>
    <div class="topbar__user">
        <span><svg class="icon" style="display:inline;vertical-align:-4px"><use href="#i-user"/></svg> {{ auth()->user()->username }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn--ghost btn--sm"><svg class="icon"><use href="#i-logout"/></svg> Logout</button>
        </form>
    </div>
</header>

<main class="dash">
    {{-- VIDEO STREAMING --}}
    <section class="panel area-video" aria-labelledby="video-title">
        <div class="panel__head">
            <span class="icon-badge"><svg><use href="#i-eye"/></svg></span>
            <div style="flex:1;min-width:0">
                <h2 id="video-title" class="display display--md">Video Streaming</h2>
                <p class="panel__sub">Monitor video in real time anywhere anytime</p>
            </div>
        </div>

        <div class="source-bar">
            <fieldset class="segmented" style="margin:0">
                <legend class="visually-hidden">Camera source</legend>
                <input type="radio" name="source" id="sourceEsp32" value="esp32">
                <label for="sourceEsp32"><svg class="icon"><use href="#i-chip"/></svg> ESP32-CAM</label>
                <input type="radio" name="source" id="sourceLaptop" value="laptop">
                <label for="sourceLaptop"><svg class="icon"><use href="#i-laptop"/></svg> Laptop Cam</label>
            </fieldset>
            <span id="sourceInfo" class="muted" style="font-size:.85rem"></span>
        </div>

        <div id="viewer" class="viewer">
            <img id="stream" alt="ESP32-CAM live stream" hidden>
            <video id="webcam" playsinline muted hidden></video>
            <div id="placeholder" class="viewer__placeholder">
                <svg><use href="#i-camera"/></svg>
                <strong>Camera is off</strong>
                <span id="placeholderText">Press “Camera On” to start streaming.</span>
            </div>
            <div class="viewer__frame"></div>
            <div class="viewer__corners"></div>
            <div class="viewer__hud" id="hud" hidden>
                <div><span class="rec">LIVE</span><span id="hudSource"></span></div>
                <div class="right"><span id="hudClock"></span><span id="hudPtz"></span></div>
            </div>
        </div>

        <div class="viewer-actions">
            <button type="button" id="cameraToggle" class="btn"><svg class="icon"><use href="#i-power"/></svg> <span>Camera On</span></button>
            <button type="button" id="snapshotBtn" class="btn btn--ghost" disabled><svg class="icon"><use href="#i-camera"/></svg> Snapshot</button>
            <span class="spacer"></span>
            <button type="button" id="alertsBtn" class="btn btn--ghost btn--sm"><svg class="icon"><use href="#i-bell"/></svg> <span>Enable alerts</span></button>
        </div>

        <div id="streamError" class="alert alert--danger" role="alert" style="margin:14px 0 0" hidden></div>

        <div id="esp32Hint" class="hint alert alert--warn" style="margin-bottom:0" hidden>
            <span style="flex:1;min-width:200px">ESP32-CAM not detected. No hardware? Use the laptop camera instead — streaming, digital pan/tilt and motion detection all work in the browser.</span>
            <button type="button" id="useLaptopBtn" class="btn btn--sm"><svg class="icon"><use href="#i-laptop"/></svg> Use laptop cam</button>
        </div>
    </section>

    {{-- MOTION DETECTOR --}}
    <section class="panel area-motion" aria-labelledby="motion-title">
        <div class="panel__head">
            <span class="icon-badge"><svg><use href="#i-motion"/></svg></span>
            <div>
                <h2 id="motion-title" class="display display--md">Motion Detector</h2>
                <p class="panel__sub">Detect intruders quickly</p>
            </div>
        </div>

        <div class="motion-status" aria-live="assertive">
            <div id="motionRing" class="motion-ring is-idle"><svg><use href="#i-motion"/></svg></div>
            <div class="motion-text">
                <strong id="motionState">No data</strong>
                <span id="motionDetail">Waiting for the sensor…</span>
            </div>
        </div>

        <div class="stat-row">
            <div class="stat"><b id="statToday">–</b><span>Motion today</span></div>
            <div class="stat"><b id="statLast">–</b><span>Last motion</span></div>
        </div>

        <div class="chart-wrap"><canvas id="motionChart" aria-label="Motion over time" role="img"></canvas></div>

        <div id="sensitivityBox" class="range" style="margin-top:14px" hidden>
            <div class="range__top"><label for="sensitivity">Sensitivity</label><output id="sensitivityValue" for="sensitivity">6</output></div>
            <input type="range" id="sensitivity" min="1" max="10" step="1" value="6">
        </div>
    </section>

    {{-- MOVEMENT CONTROL --}}
    <section class="panel area-control" aria-labelledby="control-title">
        <div class="panel__head">
            <span class="icon-badge"><svg><use href="#i-control"/></svg></span>
            <div>
                <h2 id="control-title" class="display display--md">Movement Control</h2>
                <p class="panel__sub">Control camera movement as you like</p>
            </div>
        </div>

        <div class="ptz">
            <div class="dpad" role="group" aria-label="Pan and tilt">
                <button type="button" class="up" data-step="tilt:5" aria-label="Tilt up"><svg><use href="#i-arrow"/></svg></button>
                <button type="button" class="left" data-step="pan:-5" aria-label="Pan left"><svg><use href="#i-arrow"/></svg></button>
                <button type="button" class="center" id="center" aria-label="Center camera"><svg><use href="#i-target"/></svg></button>
                <button type="button" class="right" data-step="pan:5" aria-label="Pan right"><svg><use href="#i-arrow"/></svg></button>
                <button type="button" class="down" data-step="tilt:-5" aria-label="Tilt down"><svg><use href="#i-arrow"/></svg></button>
            </div>
            <div class="ptz__sliders">
                <div class="range">
                    <div class="range__top"><label for="tilt">Rotate (Up and Down)</label><output id="tiltValue" for="tilt">95</output></div>
                    <input type="range" id="tilt" min="40" max="150" step="1" value="95">
                </div>
                <div class="range">
                    <div class="range__top"><label for="pan">Rotate (Left and Right)</label><output id="panValue" for="pan">90</output></div>
                    <input type="range" id="pan" min="0" max="180" step="1" value="90">
                </div>
                <div class="range" id="zoomBox" hidden>
                    <div class="range__top"><label for="zoom">Digital zoom</label><output id="zoomValue" for="zoom">1.6×</output></div>
                    <input type="range" id="zoom" min="1" max="3" step="0.1" value="1.6">
                </div>
            </div>
        </div>
        <p class="mode-note"><svg class="icon"><use href="#i-control"/></svg><span id="modeNote"></span></p>
    </section>

    {{-- ACTIVITY LOG --}}
    <section class="panel area-log" aria-labelledby="log-title">
        <div class="panel__head" style="flex-wrap:wrap">
            <span class="icon-badge"><svg><use href="#i-list"/></svg></span>
            <div style="flex:1;min-width:180px">
                <h2 id="log-title" class="display display--md">Activity Log</h2>
                <p class="panel__sub">Saved security events — motion with snapshots, device and camera changes</p>
            </div>
            <div class="log-filters" role="group" aria-label="Filter events">
                <button type="button" data-category="" aria-pressed="true">All</button>
                <button type="button" data-category="motion" aria-pressed="false">Motion</button>
                <button type="button" data-category="camera" aria-pressed="false">Camera</button>
                <button type="button" data-category="device" aria-pressed="false">Device</button>
            </div>
        </div>
        <ul id="log" class="log" aria-live="polite"></ul>
        <div class="log-more"><button type="button" id="logMore" class="btn btn--ghost btn--sm" hidden>Load more</button></div>
    </section>
</main>

<footer class="footer">
    <p class="lede">Smart Security Cam</p>
</footer>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script type="application/json" id="seccam-config">@json($dashboardConfig)</script>
@endsection

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/paho-mqtt/1.0.1/mqttws31.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script type="module" src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}"></script>
@endpush
