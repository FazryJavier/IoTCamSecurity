@extends('layouts.app')

@section('title', 'Login · Smart Security Cam')

@section('content')
<main class="login">
    <section aria-labelledby="brand-title">
        <h1 id="brand-title" class="display display--xl login__title">
            SM<span class="a-eye">A<svg><use href="#i-eye"/></svg></span>RT<br>Security Cam
        </h1>

        <div class="constellation">
            <svg class="connectors" viewBox="0 0 600 420" preserveAspectRatio="none" aria-hidden="true">
                <path d="M250 70 C 190 30, 120 40, 95 110"/>
                <path d="M350 70 C 410 30, 480 40, 505 110"/>
                <path d="M300 300 L 300 330"/>
            </svg>

            <div class="feature feature--left">
                <span class="icon-badge"><svg><use href="#i-eye"/></svg></span>
                <h2 class="display display--md">Video<br>Streaming</h2>
                <p>Monitor video in real time anywhere anytime</p>
            </div>

            @include('partials.emblem')

            <div class="feature feature--right">
                <span class="icon-badge"><svg><use href="#i-motion"/></svg></span>
                <h2 class="display display--md">Motion<br>Detector</h2>
                <p>Detect intruders quickly</p>
            </div>

            <div class="feature feature--bottom">
                <span class="icon-badge"><svg><use href="#i-control"/></svg></span>
                <h2 class="display display--md">Movement<br>Control</h2>
                <p>Control camera movement as you like</p>
            </div>
        </div>
    </section>

    <section class="panel login__card" aria-labelledby="login-title">
        <h2 id="login-title" class="display display--lg">Login</h2>
        <p class="panel__sub" style="margin-bottom:20px">Sign in to open the security dashboard.</p>

        <form method="POST" action="/" novalidate>
            @csrf
            @if (session('error'))
                <div class="alert alert--danger" role="alert">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert--danger" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="field">
                <label for="email">Email</label>
                <input class="input" type="email" id="email" name="email" value="{{ old('email') }}" placeholder="Enter email" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input class="input" type="password" id="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn--block">
                <svg class="icon"><use href="#i-lock"/></svg> Login
            </button>
        </form>
    </section>

    <p class="lede login__tagline" style="grid-column:1/-1">
        This product is made to monitor the state of the house from anywhere and anytime, using the ESP32 and ESP32-CAM
        to alert users when the PIR sensor detects movement.
    </p>
</main>
@endsection
