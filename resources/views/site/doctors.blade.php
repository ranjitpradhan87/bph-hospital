@php
    $q = fn (array $over = []) => http_build_query(array_filter(array_merge($filters, $over), fn ($v) => $v !== null && $v !== ''));
    $pages = (int) ceil($result['total'] / $result['page_size']);
@endphp
@extends('layouts.site', ['title' => 'Our doctors', 'description' => 'Find experienced, registered doctors and see their live appointment availability.'])
@section('content')
<div class="page-hero"><div class="container"><nav class="crumbs" aria-label="Breadcrumb"><a href="{{ url('/') }}">Home</a> / Doctors</nav><h1>Find a doctor</h1><p>Experienced, registered doctors with live appointment availability.</p></div></div>
<section class="section doctors-bg" style="--photo: url('{{ asset('img/doctors.jpg') }}')"><div class="container">
    <form class="filters" method="get" action="{{ url('/doctors') }}" data-autosubmit role="search">
        <div><label for="f-search">Name or speciality</label><input id="f-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="e.g. cardiology"></div>
        <div><label for="f-dept">Department</label><select id="f-dept" name="department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d['slug'] }}" @selected(($filters['department'] ?? '') === $d['slug'])>{{ $d['name'] }}</option>@endforeach</select></div>
        <div><label for="f-spec">Speciality</label><select id="f-spec" name="specialization"><option value="">All specialities</option>@foreach ($specializations as $s)<option @selected(($filters['specialization'] ?? '') === $s)>{{ $s }}</option>@endforeach</select></div>
        <div><label for="f-date">Available on</label><input id="f-date" type="date" name="available_on" value="{{ $filters['available_on'] ?? '' }}" min="{{ date('Y-m-d') }}"></div>
        <div style="display:flex;gap:.5rem"><button class="btn" type="submit"><x-icon name="search" /> Search</button>@if ($filters)<a class="btn btn-outline" href="{{ url('/doctors') }}">Clear</a>@endif</div>
    </form>
    <p style="color:var(--muted-fg)" aria-live="polite">{{ $result['total'] }} {{ \Illuminate\Support\Str::plural('doctor', $result['total']) }} found</p>
    @if ($result['items'])
        <div class="grid cols-4">@foreach ($result['items'] as $d)@include('site.partials.doctor-card')@endforeach</div>
        @if ($pages > 1)
            <nav class="pager" aria-label="Pagination">
                @for ($i = 1; $i <= $pages; $i++)
                    @if ($i === $result['page'])<span class="current" aria-current="page">{{ $i }}</span>@else<a href="{{ url('/doctors') }}?{{ $q(['page' => $i]) }}">{{ $i }}</a>@endif
                @endfor
            </nav>
        @endif
    @else
        <p class="empty">No doctors match your search. Try clearing the filters.</p>
    @endif
</div></section>
@endsection
