<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="cf" data-theme="{{ $theme ?? 'light' }}" @if (! isset($theme)) data-theme-auto @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            Resolve the theme before the first paint so the wrong one never flashes.
            The server already set data-theme from the user's choice or the theme cookie;
            without either (data-theme-auto) use localStorage, then the OS preference.
        --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                var root = document.documentElement;
                var theme = root.getAttribute('data-theme');

                if (root.hasAttribute('data-theme-auto')) {
                    var stored = null;
                    try { stored = localStorage.getItem('theme'); } catch (e) {}
                    theme = stored === 'light' || stored === 'dark'
                        ? stored
                        : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                    root.setAttribute('data-theme', theme);
                } else {
                    try { localStorage.setItem('theme', theme); } catch (e) {}
                }

                root.style.colorScheme = theme;
            })();
        </script>

        {{-- Canvas colour before the stylesheet loads (token "canvas", light and dark). --}}
        <style>
            html { background-color: #e6e7eb; }
            html[data-theme="dark"] { background-color: #0c0d0f; }
        </style>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#e6e7eb" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0c0d0f" media="(prefers-color-scheme: dark)">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Coffer') }}</title>
        </x-inertia::head>
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
