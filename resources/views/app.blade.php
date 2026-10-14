<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- data-inertia (Inertia v3): the <Head> title replaces this one. With the v1/v2 `inertia`
             attribute the page kept two <title> elements and the browser showed the first, so every
             page was called «Procynia» (WCAG 2.4.2, docs/wcag-accessibility-plan.md A11Y-129). --}}
        <title data-inertia="">{{ config('app.name', 'Procynia') }}</title>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-950 antialiased">
        @inertia
    </body>
</html>
