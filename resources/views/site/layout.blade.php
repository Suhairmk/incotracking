<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', 'Field sales tracking with attendance selfies, vehicle meter readings, GPS routes and a shared shop directory.')">

    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <a class="brand" href="{{ route('site.landing') }}">
            <span class="brand-mark" aria-hidden="true">F</span>
            <span>{{ config('app.name') }}</span>
        </a>
        <nav class="site-nav" aria-label="Main">
            <a href="{{ route('site.landing') }}#features">Features</a>
            <a href="{{ route('site.landing') }}#how">How it works</a>
            <a href="{{ route('site.landing') }}#directory">Shop directory</a>
            <a href="{{ route('site.privacy') }}">Privacy</a>
            <a href="{{ route('site.terms') }}">Terms</a>
        </nav>
    </div>
</header>

<main>
    @yield('content')
</main>

<footer class="site-footer">
    <div class="wrap">
        <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
        <span>
            <a href="{{ route('site.privacy') }}">Privacy policy</a>
            <a href="{{ route('site.terms') }}">Terms &amp; conditions</a>
        </span>
    </div>
</footer>
</body>
</html>
