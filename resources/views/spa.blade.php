@php
    // Entry file names are hashed per build; the Vite manifest maps the source entry to them.
    $manifestPath = public_path('app/.vite/manifest.json');
    $entry = is_file($manifestPath) ? (json_decode(file_get_contents($manifestPath), true)['src/main.tsx'] ?? null) : null;
    $name = \App\Services\PublicSite::settings()['settings']['hospital']['name'] ?? config('app.name');
@endphp
<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b7f43">
    <link rel="icon" type="image/png" href="{{ asset('img/logo.png') }}">
    <title>{{ $name }}</title>
    <script>window.__BPH__ = {!! json_encode(['name' => $name], JSON_HEX_TAG | JSON_HEX_AMP) !!};</script>
    @if ($entry)
        @foreach ($entry['css'] ?? [] as $css)<link rel="stylesheet" href="{{ asset('app/'.$css) }}">@endforeach
    @endif
</head>
<body class="flex min-h-full flex-col antialiased">
    <div id="root" class="flex min-h-screen flex-col"></div>
    <noscript>This area needs JavaScript. Please enable it in your browser.</noscript>
    @if ($entry)
        <script type="module" src="{{ asset('app/'.$entry['file']) }}"></script>
    @else
        <p style="padding:2rem;font-family:sans-serif">The admin app has not been built yet. Run <code>npm run build</code> in the <code>spa</code> folder.</p>
    @endif
</body>
</html>
