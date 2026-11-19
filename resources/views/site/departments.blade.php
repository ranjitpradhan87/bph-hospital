@extends('layouts.site', ['title' => 'Departments', 'description' => 'Browse our medical departments and book a consultation with a specialist.'])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / Departments</nav><h1>Departments</h1><p>Find the right specialist for your condition and book a consultation.</p></div></div>
<section class="section"><div class="container">
    @if ($departments)<div class="grid cols-4">@foreach ($departments as $d)@include('site.partials.department-card')@endforeach</div>
    @else<p class="empty">Departments will be listed here soon.</p>@endif
</div></section>
@endsection
