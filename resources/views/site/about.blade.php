@php $c = $site['content'] ?? []; $h = $site['hospital'] ?? []; @endphp
@extends('layouts.site', ['title' => 'About us', 'description' => $c['about']['summary'] ?? 'About our hospital'])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / About</nav><h1>About {{ $h['name'] ?? config('app.name') }}</h1><p>{{ $c['about']['summary'] ?? '' }}</p></div></div>
<section class="section"><div class="container">
    <div class="grid cols-2">
        @if (! empty($c['about']['mission']))<div class="card"><span class="badge teal">Our mission</span><p style="margin-top:.75rem;font-size:1.05rem;color:var(--fg)">{{ $c['about']['mission'] }}</p></div>@endif
        @if (! empty($c['about']['vision']))<div class="card"><span class="badge">Our vision</span><p style="margin-top:.75rem;font-size:1.05rem;color:var(--fg)">{{ $c['about']['vision'] }}</p></div>@endif
    </div>
    @if (! empty($c['about']['values']))
        <h2 style="margin:2.5rem 0 1rem">What we value</h2>
        <ul class="check-list">@foreach ($c['about']['values'] as $v)<li><x-icon name="check-circle" /> {{ $v }}</li>@endforeach</ul>
    @endif
    @if (! empty($h['about_image_url']))<img src="{{ $h['about_image_url'] }}" alt="" loading="lazy" style="border-radius:var(--radius);margin-top:2rem;width:100%;max-height:28rem;object-fit:cover">@endif
    <div class="btn-row"><a class="btn btn-lg" href="{{ url('/doctors') }}">Meet our doctors</a><a class="btn btn-lg btn-outline" href="{{ url('/contact') }}">Contact us</a></div>
</div></section>
@endsection
