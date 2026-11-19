@php
    use App\Support\SiteFormat as F;
    $h = $site['hospital'] ?? [];
    $hospitalName = $h['name'] ?? config('app.name');
    $pageTitle = isset($title) ? $title.' | '.$hospitalName : $hospitalName.(! empty($h['tagline']) ? ' – '.$h['tagline'] : '');
    $metaDescription = $description ?? ($site['content']['hero']['subtitle'] ?? '');
    $canonical = url()->current();
    $nav = [['/', 'Home'], ['/about', 'About'], ['/departments', 'Departments'], ['/doctors', 'Doctors'], ['/services', 'Services'], ['/facilities', 'Facilities'], ['/contact', 'Contact']];
    $isActive = fn ($href) => $href === '/' ? request()->is('/') : request()->is(ltrim($href, '/').'*');
    $days = F::workingDays($h['working_days'] ?? []);
    $hours = $h['working_hours'] ?? null;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($metaDescription, 160) }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $hospitalName }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($metaDescription, 200) }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if (! empty($h['hero_image_url']))<meta property="og:image" content="{{ url($h['hero_image_url']) }}">@endif
    <meta name="theme-color" content="#2563eb">
    @if (! empty($noindex))<meta name="robots" content="noindex, follow">@endif
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) }}">
    @stack('head')
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="site-header">
    @if (! empty($h['emergency_phone']) || ! empty($h['phone']))
        <div class="topbar">
            <div class="container">
                @if ($days && $hours)<span>OPD: {{ $days }} · {{ $hours['start'] }} – {{ $hours['end'] }}</span>@else<span></span>@endif
                <span>
                    @if (! empty($h['emergency_phone']))<a href="{{ F::tel($h['emergency_phone']) }}"><x-icon name="phone" class="icon" /> Emergency: {{ $h['emergency_phone'] }}</a>@endif
                </span>
            </div>
        </div>
    @endif
    <div class="container">
        <div class="header-row">
            <a href="{{ url('/') }}" class="brand" aria-label="{{ $hospitalName }} home">
                @if (! empty($h['logo_url']))
                    <img src="{{ $h['logo_url'] }}" alt="{{ $hospitalName }}">
                @else
                    <span class="brand-mark"><x-icon name="heart-pulse" /></span><span>{{ $hospitalName }}</span>
                @endif
            </a>
            <nav class="nav" aria-label="Main">
                @foreach ($nav as [$href, $label])
                    <a href="{{ url($href) }}" @if ($isActive($href)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <a href="{{ url('/book') }}" class="btn header-cta">Book appointment</a>
            <button class="menu-btn" type="button" aria-expanded="false" aria-controls="mobile-nav" data-menu-toggle>
                <span class="sr-only">Menu</span><x-icon name="menu" />
            </button>
        </div>
        <nav id="mobile-nav" class="mobile-nav" aria-label="Mobile">
            @foreach ($nav as [$href, $label])
                <a href="{{ url($href) }}" @if ($isActive($href)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a href="{{ url('/book') }}" class="btn" style="margin-top:.5rem;width:100%">Book appointment</a>
        </nav>
    </div>
</header>

<main id="main">
    @yield('content')
</main>

<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="footer-brand"><x-icon name="heart-pulse" /> {{ $hospitalName }}</div>
                @if (! empty($h['tagline']))<p style="font-size:.92rem">{{ $h['tagline'] }}</p>@endif
                @if (! empty($h['emergency_phone']))
                    <a href="{{ F::tel($h['emergency_phone']) }}" class="btn btn-danger"><x-icon name="phone" /> Emergency {{ $h['emergency_phone'] }}</a>
                @endif
            </div>
            <nav aria-label="Footer">
                <h2>Hospital</h2>
                <ul>
                    @foreach (array_slice($nav, 1) as [$href, $label])<li><a href="{{ url($href) }}">{{ $label }}</a></li>@endforeach
                    <li><a href="{{ url('/book') }}">Book appointment</a></li>
                </ul>
            </nav>
            <nav aria-label="Departments">
                <h2>Departments</h2>
                <ul>
                    @foreach (array_slice($site['departments'], 0, 6) as $d)<li><a href="{{ url('/departments/'.$d['slug']) }}">{{ $d['name'] }}</a></li>@endforeach
                    <li><a href="{{ url('/departments') }}" style="color:var(--teal);font-weight:600">All departments →</a></li>
                </ul>
            </nav>
            <div>
                <h2>Visit us</h2>
                <ul>
                    @if ($addr = F::address($h['address'] ?? null))<li class="row"><x-icon name="map-pin" /><span>{{ $addr }}</span></li>@endif
                    @if (! empty($h['phone']))<li class="row"><x-icon name="phone" /><a href="{{ F::tel($h['phone']) }}">{{ $h['phone'] }}</a></li>@endif
                    @if (! empty($h['email']))<li class="row"><x-icon name="mail" /><a href="mailto:{{ $h['email'] }}">{{ $h['email'] }}</a></li>@endif
                    @if ($days && $hours)<li class="row"><x-icon name="clock" /><span>{{ $days }}, {{ $hours['start'] }} – {{ $hours['end'] }}</span></li>@endif
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© {{ date('Y') }} {{ $hospitalName }}. All rights reserved.</span>
            <span>For emergencies, call {{ $h['emergency_phone'] ?? 'your local emergency number' }}. This website does not provide medical advice.</span>
        </div>
    </div>
</footer>
<script src="{{ asset('js/site.js') }}?v={{ @filemtime(public_path('js/site.js')) }}" defer></script>
</body>
</html>
