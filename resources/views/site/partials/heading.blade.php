<div class="section-head {{ ! empty($center) ? 'center' : '' }}">
    <div>
        @if (! empty($eyebrow))<p class="eyebrow">{{ $eyebrow }}</p>@endif
        <h2>{{ $title }}</h2>
        @if (! empty($text))<p>{{ $text }}</p>@endif
    </div>
    @if (! empty($href))<a class="btn btn-outline" href="{{ url($href) }}">{{ $label }} <x-icon name="arrow-right" /></a>@endif
</div>
