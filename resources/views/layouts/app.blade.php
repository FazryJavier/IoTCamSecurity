<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#071a2b">
    <title>@yield('title', 'Smart Security Cam')</title>
    <link rel="icon" href="/img/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Raleway:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/seccam.css') }}?v={{ filemtime(public_path('css/seccam.css')) }}">
    @stack('head')
</head>
<body class="@yield('body_class')">
    @include('partials.icons')

    <svg class="corner corner--left" aria-hidden="true"><use href="#i-chevrons"/></svg>
    <svg class="corner corner--right" aria-hidden="true"><use href="#i-chevrons"/></svg>

    @yield('content')

    <div class="slashes" aria-hidden="true"><span></span><span></span><span></span><span></span></div>

    @stack('scripts')
</body>
</html>
