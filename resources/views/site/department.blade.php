@extends('layouts.site', ['title' => $dept['name'], 'description' => $dept['short_description'] ?: ($dept['name'].' department and its specialist doctors.')])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / <a href="{{ url('/departments') }}">Departments</a> / {{ $dept['name'] }}</nav><h1>{{ $dept['name'] }}</h1>@if ($dept['short_description'])<p>{{ $dept['short_description'] }}</p>@endif</div></div>
<section class="section"><div class="container">
    @if ($dept['image_url'])<img src="{{ $dept['image_url'] }}" alt="" style="border-radius:var(--radius);width:100%;max-height:22rem;object-fit:cover;margin-bottom:1.5rem">@endif
    @if ($dept['description'])<div class="prose">@foreach (preg_split('/\n{2,}/', $dept['description']) as $para)<p>{{ $para }}</p>@endforeach</div>@endif
    <h2 style="margin:2rem 0 1rem">Doctors in {{ $dept['name'] }}</h2>
    @if ($doctors)<div class="grid cols-3">@foreach ($doctors as $d)@include('site.partials.doctor-card')@endforeach</div>
    @else<p class="empty">No doctors are currently listed in this department.</p>@endif
</div></section>
@endsection
