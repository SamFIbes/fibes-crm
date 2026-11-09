<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <meta name="color-scheme" content="light dark">
        <title inertia>{{ config('app.name', 'FIBES') }}</title>
        <link rel="icon" href="/brand/favicon.ico" sizes="any">
        <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#06131a">
        <script nonce="{{ $cspNonce ?? '' }}">
            (function () {
                try {
                    var stored = localStorage.getItem('fibes-theme');
                    var dark = stored === 'dark' || (stored !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    if (dark) document.documentElement.classList.add('dark');
                } catch (e) {}
            })();
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="h-full bg-background text-foreground antialiased">
        @inertia
    </body>
</html>
