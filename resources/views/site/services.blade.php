@php $c = $site['content'] ?? []; $items = $c['services'] ?? []; @endphp
@extends('layouts.site', ['title' => 'Our services', 'description' => 'Comprehensive care under one roof'])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / Our services</nav><h1>Our services</h1><p>Comprehensive care under one roof</p></div></div>
<section class="section"><div class="container">
    @if ($items)
    <div class="grid cols-3">
        @foreach ($items as $s)
            <div class="card feature"><span class="ico teal"><x-icon :name="$s['icon']" /></span><div><h3>{{ $s['title'] }}</h3><p>{{ $s['description'] }}</p></div></div>
        @endforeach
    </div>
    @else
    <p class="empty">Details will be added soon. Please <a href="{{ url('/contact') }}">contact us</a> for information.</p>
    @endif
    <div class="btn-row"><a class="btn btn-lg" href="{{ url('/book') }}">Book an appointment</a></div>
</div></section>
@endsection
