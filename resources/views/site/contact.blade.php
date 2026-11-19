@php use App\Support\SiteFormat as F; $h = $site['hospital'] ?? []; $addr = F::address($h['address'] ?? null); $hoursDays = F::workingDays($h['working_days'] ?? []); @endphp
@extends('layouts.site', ['title' => 'Contact us', 'description' => 'Contact the hospital: address, phone numbers, opening hours and a message form.'])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / Contact</nav><h1>Contact us</h1><p>We would love to hear from you. For emergencies, please call rather than write.</p></div></div>
<section class="section"><div class="container split" style="align-items:start">
    <div>
        <ul class="grid" style="gap:1rem">
            @if ($addr)<li class="card feature"><span class="ico blue"><x-icon name="map-pin" /></span><div><h3>Address</h3><p>{{ $addr }}</p></div></li>@endif
            @if (! empty($h['phone']))<li class="card feature"><span class="ico blue"><x-icon name="phone" /></span><div><h3>Appointments</h3><p><a href="{{ F::tel($h['phone']) }}">{{ $h['phone'] }}</a></p></div></li>@endif
            @if (! empty($h['emergency_phone']))<li class="card feature"><span class="ico red"><x-icon name="phone" /></span><div><h3>Emergency (24×7)</h3><p><a href="{{ F::tel($h['emergency_phone']) }}">{{ $h['emergency_phone'] }}</a></p></div></li>@endif
            @if (! empty($h['email']))<li class="card feature"><span class="ico blue"><x-icon name="mail" /></span><div><h3>Email</h3><p><a href="mailto:{{ $h['email'] }}">{{ $h['email'] }}</a></p></div></li>@endif
            @if ($hoursDays)<li class="card feature"><span class="ico blue"><x-icon name="clock" /></span><div><h3>OPD hours</h3><p>{{ $hoursDays }}@if (! empty($h['working_hours'])), {{ $h['working_hours']['start'] }} – {{ $h['working_hours']['end'] }}@endif</p></div></li>@endif
        </ul>
        @if (! empty($h['map_embed_url']))<iframe class="map" style="margin-top:1rem" src="{{ $h['map_embed_url'] }}" title="Map to the hospital" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>@endif
    </div>
    <div class="card" id="form">
        <h2 style="font-size:1.3rem">Send us a message</h2>
        @if (session('sent'))<p class="alert ok" role="status">Thank you. Your message has been received and we will get back to you soon.</p>@endif
        <form method="post" action="{{ url('/contact') }}" novalidate>
            @csrf
            <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
            @foreach ([['name', 'Your name', 'text', 'name'], ['email', 'Email', 'email', 'email'], ['phone', 'Mobile (optional)', 'tel', 'tel'], ['subject', 'Subject (optional)', 'text', 'off']] as [$f, $label, $type, $ac])
                <div class="field"><label for="c-{{ $f }}">{{ $label }}</label><input id="c-{{ $f }}" name="{{ $f }}" type="{{ $type }}" value="{{ old($f) }}" autocomplete="{{ $ac }}" @error($f) aria-invalid="true" aria-describedby="e-{{ $f }}" @enderror>@error($f)<p class="error" id="e-{{ $f }}">{{ $message }}</p>@enderror</div>
            @endforeach
            <div class="field"><label for="c-message">Message</label><textarea id="c-message" name="message" required @error('message') aria-invalid="true" aria-describedby="e-message" @enderror>{{ old('message') }}</textarea>@error('message')<p class="error" id="e-message">{{ $message }}</p>@enderror</div>
            <button class="btn btn-lg" type="submit">Send message</button>
            <p class="hint">Please do not send medical details through this form.</p>
        </form>
    </div>
</div></section>
@endsection
