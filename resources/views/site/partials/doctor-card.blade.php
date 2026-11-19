@php use App\Support\SiteFormat as F; @endphp
<a class="card" href="{{ url('/doctors/'.$d['id']) }}">
    <div class="doctor-top">
        @if ($d['photo_url'])
            <img class="avatar" src="{{ $d['photo_url'] }}" alt="" loading="lazy" width="72" height="72">
        @else
            <span class="avatar" aria-hidden="true">{{ F::initials($d['full_name']) }}</span>
        @endif
        <div>
            <h3>{{ $d['full_name'] }}</h3>
            <p>{{ $d['specialization'] }}</p>
        </div>
    </div>
    <div class="meta">
        <span class="badge">{{ $d['qualification'] }}</span>
        @if ($d['experience_years'])<span class="badge gray">{{ $d['experience_years'] }} yrs experience</span>@endif
        @foreach (array_slice($d['departments'], 0, 2) as $dep)<span class="badge teal">{{ $dep['name'] }}</span>@endforeach
    </div>
    <div class="avail">
        <span>{{ F::fee($d['consultation_fee']) }} consultation</span>
        @if ($d['next_available_date'])
            <span class="badge green">Next: {{ F::dateLabel($d['next_available_date']) }}</span>
        @else
            <span class="badge gray">No open slots</span>
        @endif
    </div>
</a>
