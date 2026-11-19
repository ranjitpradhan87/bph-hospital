<a class="card" href="{{ url('/departments/'.$d['slug']) }}">
    <span class="ico blue" style="width:2.75rem;height:2.75rem;border-radius:12px;display:grid;place-items:center"><x-icon :name="$d['icon'] ?: 'stethoscope'" /></span>
    <h3>{{ $d['name'] }}</h3>
    @if ($d['short_description'])<p>{{ $d['short_description'] }}</p>@endif
    <p style="margin-top:.6rem"><span class="badge teal">{{ $d['doctor_count'] }} {{ \Illuminate\Support\Str::plural('doctor', $d['doctor_count']) }}</span></p>
</a>
