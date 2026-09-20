<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'CLASSLY') }}</title>

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" href="/logo.png">
    <link rel="apple-touch-icon" href="/logo.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <!-- PERFORMANCE — CDN + font loading: bunny.net already serves
         these as a CDN. display=swap avoids blocking text rendering
         on the web font (renders with a fallback font immediately,
         swaps once the font file arrives). -->
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|baloo-2:600,700,800&display=swap" rel="stylesheet" />

    @routes
    {{-- PERFORMANCE — defer non-critical scripts: @vite emits
         type="module" script tags, which browsers defer and execute
         in order automatically (no separate `defer` attribute
         needed/possible on module scripts). Combined with the
         per-page code splitting in resources/js/app.js
         (import.meta.glob('./Pages/**/*.vue')), only the current
         page's chunk + shared vendor chunks (see vite.config.js
         manualChunks) load per request. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>