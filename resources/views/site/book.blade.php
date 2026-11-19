@php use App\Support\SiteFormat as F; $h = $site['hospital'] ?? []; @endphp
@extends('layouts.site', ['title' => 'Book an appointment', 'description' => 'Choose a doctor and see live appointment availability.'])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / Book appointment</nav><h1>Book an appointment</h1><p>Choose a doctor to see their open slots.</p></div></div>
<section class="section"><div class="container">
    <p class="alert info">Pick a doctor to see open times. You only need your mobile number to book — no account needed first.</p>
    @if ($doctors)<div class="grid cols-4">@foreach ($doctors as $d)@include('site.partials.doctor-card')@endforeach</div>@else<p class="empty">Doctors will be listed here soon.</p>@endif
</div></section>
@endsection
