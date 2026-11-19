@php
    use App\Support\SiteFormat as F;
    $h = $site['hospital'] ?? [];
    $c = $site['content'] ?? [];
    $name = $h['name'] ?? config('app.name');
@endphp
@extends('layouts.site')

@push('head')
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org', '@type' => 'Hospital', 'name' => $name, 'url' => url('/'),
        'telephone' => $h['phone'] ?? null, 'email' => $h['email'] ?? null, 'image' => ! empty($h['hero_image_url']) ? url($h['hero_image_url']) : null,
        'address' => ! empty($h['address']) ? array_filter(['@type' => 'PostalAddress', 'streetAddress' => trim(($h['address']['line1'] ?? '').' '.($h['address']['line2'] ?? '')),
            'addressLocality' => $h['address']['city'] ?? null, 'addressRegion' => $h['address']['state'] ?? null, 'postalCode' => $h['address']['pincode'] ?? null, 'addressCountry' => 'IN']) : null,
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<section class="hero hero-banner" data-slider aria-roledescription="carousel" aria-label="Welcome">
    @php $slideImage = ! empty($h['hero_image_url']) ? $h['hero_image_url'] : asset('img/hero-hospital.jpg'); @endphp
    <div class="slide is-active" style="--slide-img: url('{{ $slideImage }}')" role="group" aria-roledescription="slide" aria-label="1 of 1">
        <div class="container hero-copy">
            <p class="pill"><x-icon name="shield-check" /> {{ $h['tagline'] ?? 'Multi-speciality hospital' }}</p>
            <h1>{{ $c['hero']['title'] ?? 'Trusted care for every stage of life' }}</h1>
            <p class="lead">{{ $c['hero']['subtitle'] ?? '' }}</p>
            <div class="btn-row">
                <a class="btn btn-lg" href="{{ url('/book') }}"><x-icon name="calendar-check" /> Book an appointment</a>
                <a class="btn btn-lg btn-outline" href="{{ url('/doctors') }}"><x-icon name="search" /> Find a doctor</a>
            </div>
            @if ($site['stats']['doctors'] > 0 || $site['stats']['departments'] > 0)
                <dl class="stats">
                    @if ($site['stats']['doctors'] > 0)<div class="stat"><dd><b>{{ $site['stats']['doctors'] }}</b></dd><dt>Specialist doctors</dt></div>@endif
                    @if ($site['stats']['departments'] > 0)<div class="stat"><dd><b>{{ $site['stats']['departments'] }}</b></dd><dt>Departments</dt></div>@endif
                    <div class="stat"><dd><b>Online</b></dd><dt>Live slot booking</dt></div>
                </dl>
            @endif
        </div>
    </div>
</section>

<div class="container quick">
    <div class="quick-grid">
        <a href="{{ url('/book') }}"><span class="ico blue"><x-icon name="calendar-check" /></span><span><b>Book appointment</b><small>Choose a doctor and time</small></span></a>
        <a href="{{ url('/doctors') }}"><span class="ico blue"><x-icon name="search" /></span><span><b>Find a doctor</b><small>Search by name or speciality</small></span></a>
        @if (! empty($h['emergency_phone']))
            <a href="{{ F::tel($h['emergency_phone']) }}"><span class="ico red"><x-icon name="phone" /></span><span><b>Emergency</b><small>{{ $h['emergency_phone'] }}</small></span></a>
        @else
            <a href="{{ url('/contact') }}"><span class="ico blue"><x-icon name="phone" /></span><span><b>Call us</b><small>Talk to our help desk</small></span></a>
        @endif
        <a href="{{ url('/contact') }}"><span class="ico blue"><x-icon name="map-pin" /></span><span><b>Visit us</b><small>Directions &amp; contact</small></span></a>
    </div>
</div>

@if (! empty($c['about']['summary']))
<section class="section">
    <div class="container split">
        <div class="about-images">
            <div class="about-img about-img-a"><img src="{{ asset('img/about-mother-baby.webp') }}" alt="A smiling mother lifting her baby" width="800" height="830" loading="lazy"></div>
            <div class="about-img about-img-b"><img src="{{ asset('img/about-care.webp') }}" alt="A doctor holding a patient's hand in reassurance" width="1000" height="667" loading="lazy"></div>
        </div>
        <div class="about-copy">
            @include('site.partials.heading', ['eyebrow' => 'About us', 'title' => 'Welcome to '.$name, 'text' => $c['about']['summary']])
            @if (! empty($c['about']['values']))
                <ul class="check-list">@foreach ($c['about']['values'] as $v)<li><x-icon name="check-circle" /> {{ $v }}</li>@endforeach</ul>
            @endif
            <a class="btn btn-outline btn-lg" href="{{ url('/about') }}">More about us <x-icon name="arrow-right" /></a>
        </div>
    </div>
</section>
@endif

@if ($departments)
<section class="section muted">
    <div class="container">
        @include('site.partials.heading', ['eyebrow' => 'Departments', 'title' => 'Specialist care across departments', 'text' => 'Find the right specialist for your condition and book a consultation.', 'href' => '/departments', 'label' => 'All departments'])
        <div class="grid cols-4">@foreach ($departments as $d)@include('site.partials.department-card')@endforeach</div>
    </div>
</section>
@endif

@if ($doctors)
<section class="section doctors-bg" style="--photo: url('{{ asset('img/doctors.jpg') }}')">
    <div class="container">
        @include('site.partials.heading', ['eyebrow' => 'Our doctors', 'title' => 'Meet our specialists', 'text' => 'Experienced, registered doctors with live appointment availability.', 'href' => '/doctors', 'label' => 'Find a doctor'])
        <div class="grid cols-4">@foreach ($doctors as $d)@include('site.partials.doctor-card')@endforeach</div>
    </div>
</section>
@endif

@if (! empty($c['services']))
<section class="section muted">
    <div class="container">
        @include('site.partials.heading', ['eyebrow' => 'Medical services', 'title' => 'Comprehensive care under one roof', 'href' => '/services', 'label' => 'All services'])
        <div class="grid cols-3">
            @foreach ($c['services'] as $s)
                <div class="card feature"><span class="ico teal"><x-icon :name="$s['icon']" /></span><div><h3>{{ $s['title'] }}</h3><p>{{ $s['description'] }}</p></div></div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if (! empty($c['facilities']))
<section class="section section-photo" style="--photo: url('{{ asset('img/facilities.jpg') }}')">
    <div class="container">
        @include('site.partials.heading', ['eyebrow' => 'Facilities', 'title' => 'Built around patient comfort and safety', 'href' => '/facilities', 'label' => 'Explore facilities'])
        <div class="grid cols-3">
            @foreach (array_slice($c['facilities'], 0, 6) as $f)
                <div class="tile"><x-icon :name="$f['icon']" /><h3 style="margin-top:.6rem">{{ $f['title'] }}</h3><p style="color:var(--muted-fg);margin:0">{{ $f['description'] }}</p></div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if (! empty($c['testimonials']))
<section class="section muted">
    <div class="container">
        @include('site.partials.heading', ['eyebrow' => 'Patient stories', 'title' => 'What our patients say', 'center' => true])
        <div class="grid cols-3">
            @foreach (array_slice($c['testimonials'], 0, 3) as $t)
                <figure class="card quote" style="margin:0"><x-icon name="quote" /><blockquote>{{ $t['quote'] }}</blockquote><figcaption><b>{{ $t['name'] }}</b>@if (! empty($t['context']))<span style="color:var(--muted-fg)"> · {{ $t['context'] }}</span>@endif</figcaption></figure>
            @endforeach
        </div>
    </div>
</section>
@endif

@if (! empty($h['emergency_phone']))
<section class="banner"><div class="container"><div><h2 style="margin:0">Medical emergency?</h2><p style="margin:.25rem 0 0">Do not wait for an appointment. Call us now.</p></div><a class="btn btn-lg" href="{{ F::tel($h['emergency_phone']) }}"><x-icon name="phone" /> {{ $h['emergency_phone'] }}</a></div></section>
@endif
@endsection
