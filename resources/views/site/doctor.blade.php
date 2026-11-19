@php use App\Support\SiteFormat as F; $h = $site['hospital'] ?? []; @endphp
@extends('layouts.site', ['title' => $doctor['full_name'].' – '.$doctor['specialization'], 'description' => $doctor['full_name'].', '.$doctor['qualification'].'. '.$doctor['specialization'].' with '.$doctor['experience_years'].' years of experience. Check availability and book online.'])
@push('head')
<script type="application/ld+json">{!! json_encode(array_filter(['@context' => 'https://schema.org', '@type' => 'Physician', 'name' => $doctor['full_name'], 'medicalSpecialty' => $doctor['specialization'], 'description' => $doctor['qualification'], 'url' => url('/doctors/'.$doctor['id']), 'image' => $doctor['photo_url'] ? url($doctor['photo_url']) : null, 'memberOf' => ['@type' => 'Hospital', 'name' => $h['name'] ?? config('app.name')]]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush
@section('content')
<div class="page-hero"><div class="container">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / <a href="{{ url('/doctors') }}">Doctors</a> / {{ $doctor['full_name'] }}</nav>
    <div class="doctor-top">
        @if ($doctor['photo_url'])<img class="avatar" style="width:6rem;height:6rem" src="{{ $doctor['photo_url'] }}" alt="{{ $doctor['full_name'] }}" width="96" height="96">@else<span class="avatar" style="width:6rem;height:6rem;font-size:1.8rem" aria-hidden="true">{{ F::initials($doctor['full_name']) }}</span>@endif
        <div><h1 style="margin:0">{{ $doctor['full_name'] }}</h1><p style="margin:.25rem 0 0">{{ $doctor['specialization'] }} · {{ $doctor['qualification'] }}</p></div>
    </div>
</div></div>
<section class="section"><div class="container profile-grid">
    <div>
        @if ($doctor['bio'])<h2>About</h2><div class="prose">@foreach (preg_split('/\n{2,}/', $doctor['bio']) as $para)<p>{{ $para }}</p>@endforeach</div>@endif
        <h2 style="margin-top:2rem">Book a slot</h2>
        <form method="get" action="{{ url('/doctors/'.$doctor['id']) }}" class="field" style="max-width:16rem" data-autosubmit>
            <label for="slot-date">Choose a date</label>
            <input id="slot-date" type="date" name="date" value="{{ $date }}" min="{{ $minDate }}">
            <noscript><button class="btn btn-outline" style="margin-top:.5rem">Show slots</button></noscript>
        </form>
        @if ($date && $slots)
            <p style="margin:0;color:var(--muted-fg)">{{ count($slots) }} open {{ \Illuminate\Support\Str::plural('slot', count($slots)) }} on {{ F::dateLabel($date) }}</p>
            <ul class="slots">@foreach ($slots as $s)<li><a class="slot" href="{{ url('/appointment') }}?doctor={{ $doctor['id'] }}&amp;date={{ $date }}">{{ F::time($s['start_time']) }}@if ($s['appointment_type'] === 'online') <span class="badge teal">online</span>@endif</a></li>@endforeach</ul>
            <p style="margin-top:1rem"><a class="btn btn-lg" href="{{ url('/appointment') }}?doctor={{ $doctor['id'] }}&amp;date={{ $date }}"><x-icon name="calendar-check" /> Book with {{ $doctor['full_name'] }}</a></p>
        @elseif ($date)
            <p class="empty">No open slots on {{ F::dateLabel($date) }}. Try another date.</p>
        @else
            <p class="empty">No open slots at the moment. Please check back soon or call the hospital.</p>
        @endif
        <p class="hint" style="margin-top:1rem">You will be asked to sign in or create a free account to confirm. Prefer to book by phone? @if (! empty($h['phone']))Call <a href="{{ F::tel($h['phone']) }}">{{ $h['phone'] }}</a>.@else<a href="{{ url('/contact') }}">Contact us</a>.@endif</p>
        @if ($doctor['weekly_hours'])
            <h2 style="margin-top:2rem">Weekly hours</h2>
            <table class="week"><tbody>@foreach ($doctor['weekly_hours'] as $w)<tr><th scope="row">{{ F::dayName($w['day_of_week']) }}</th><td>{{ F::time($w['start_time']) }} – {{ F::time($w['end_time']) }}</td><td>{{ $w['appointment_type'] === 'online' ? 'Online' : 'In person' }}</td></tr>@endforeach</tbody></table>
        @endif
    </div>
    <aside class="card">
        <h2 style="font-size:1.1rem">At a glance</h2>
        <dl class="facts">
            <div><dt>Consultation fee</dt><dd>{{ F::fee($doctor['consultation_fee']) }}</dd></div>
            <div><dt>Experience</dt><dd>{{ $doctor['experience_years'] }} years</dd></div>
            @if ($doctor['languages'])<div><dt>Languages</dt><dd>{{ $doctor['languages'] }}</dd></div>@endif
            <div><dt>Registration no.</dt><dd>{{ $doctor['registration_number'] }}</dd></div>
            @foreach ($doctor['departments'] as $dep)<div><dt>Department</dt><dd><a href="{{ url('/departments/'.$dep['slug']) }}">{{ $dep['name'] }}</a></dd></div>@endforeach
        </dl>
    </aside>
</div></section>
@endsection
