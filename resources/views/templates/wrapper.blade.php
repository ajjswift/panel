<!DOCTYPE html>
<html>
    <head>
        <title>{{ $resellerBranding->appName ?? config('app.name', 'Pterodactyl') }}</title>

        {{-- Apply the persisted theme before first paint to avoid a flash of the wrong theme. --}}
        <script>
            (function () {
                try {
                    var mode = localStorage.getItem('pterodactyl:theme') || 'system';
                    var dark = mode === 'dark' || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    var root = document.documentElement;
                    root.setAttribute('data-theme', dark ? 'dark' : 'light');
                    root.classList.toggle('dark', dark);
                    root.style.colorScheme = dark ? 'dark' : 'light';
                } catch (e) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            })();
        </script>

        @section('meta')
            <meta charset="utf-8">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
            <meta name="csrf-token" content="{{ csrf_token() }}">
            <meta name="robots" content="noindex">
            <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png">
            <link rel="icon" type="image/png" href="/favicons/favicon-32x32.png" sizes="32x32">
            <link rel="icon" type="image/png" href="/favicons/favicon-16x16.png" sizes="16x16">
            <link rel="manifest" href="/favicons/manifest.json">
            <link rel="mask-icon" href="/favicons/safari-pinned-tab.svg" color="#bc6e3c">
            <link rel="shortcut icon" href="{{ $resellerBranding->faviconUrl ?? '/favicons/favicon.ico' }}">
            <meta name="msapplication-config" content="/favicons/browserconfig.xml">
            <meta name="theme-color" content="{{ $resellerBranding->brandHex ?? '#0e4688' }}">
        @show

        @section('user-data')
            @if(!is_null(Auth::user()))
                <script>
                    window.PterodactylUser = {!! json_encode(Auth::user()->toVueObject()) !!};
                </script>
            @endif
            @if(!empty($siteConfiguration))
                <script>
                    window.SiteConfiguration = {!! json_encode($siteConfiguration) !!};
                </script>
            @endif
        @show

        @yield('assets')

        @include('layouts.scripts')

        {{--
            Reseller white-label overrides. Must come after the bundle's stylesheet
            so it wins, and only touches the brand/accent ramps — both theme blocks
            in tailwind.css reference those through var(), so one :root override
            re-colors light and dark alike. Every value here is a server-generated
            numeric triplet or a validated hex; nothing user-typed is interpolated
            raw.
        --}}
        @if(isset($resellerBranding) && !$resellerBranding->isEmpty())
            <style id="reseller-branding">
                :root {
            {!! $resellerBranding->cssVariables() !!}
                }
            </style>
        @endif
    </head>
    <body class="{{ $css['body'] ?? 'bg-neutral-50' }}">
        @section('content')
            @yield('above-container')
            @yield('container')
            @yield('below-container')
        @show
        @section('scripts')
            {!! $asset->js('main.js') !!}
        @show
    </body>
</html>
