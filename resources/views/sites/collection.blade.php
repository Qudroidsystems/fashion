@extends('sites.layout')
@section('title', 'Collection')

@section('body')
    <section>
        <div class="wrap">
            <h1 style="font-size:clamp(2.4rem,5vw,4rem)">Collection</h1>
            <p class="muted" style="margin:1rem 0 40px;max-width:60ch">Every piece is made to your measurements. Choose a style to see the details, or tell us what you would like changed.</p>
            @if($styles->isEmpty())
                <p class="notice">The collection is being updated. <a href="{{ $base }}/contact">Get in touch</a> to talk about what you would like made.</p>
            @else
            <div class="grid">
                @foreach($styles as $s)
                    <a class="card" href="{{ $base }}/collection/{{ $s->public_id }}">
                        <div class="pic">@include('sites.partials-pic', ['src' => $s->currentVersion?->cover()?->url, 'alt' => $s->name])</div>
                        <h3>{{ $s->name }}</h3>
                        <div class="price">{{ $s->category?->name }}@if(($site['show_prices'] ?? true) && $s->currentVersion?->base_price > 0){{ $s->category ? ' · ' : '' }}From {{ \App\Support\Money::format($s->currentVersion->base_price) }}@endif</div>
                    </a>
                @endforeach
            </div>
            @endif
        </div>
    </section>
@endsection
