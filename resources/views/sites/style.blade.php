@extends('sites.layout')
@section('title', $style->name)

@push('css')
    .detail { display: grid; grid-template-columns: 1.1fr .9fr; gap: 3rem; align-items: start; }
    .gallery { display: grid; gap: 14px; grid-template-columns: 1fr 1fr; }
    .gallery .pic { aspect-ratio: 3 / 4; border-radius: 16px; overflow: hidden; background: var(--soft); }
    .gallery .pic:first-child { grid-column: 1 / -1; aspect-ratio: 4 / 5; }
    .gallery img { width: 100%; height: 100%; object-fit: cover; }
    .info { position: sticky; top: 100px; }
    .info .price { font-size: 1.25rem; margin: .8rem 0 1.4rem; }
    .opts { list-style: none; padding: 0; margin: 1.2rem 0 1.8rem; border-top: 1px solid var(--line); }
    .opts li { display: flex; justify-content: space-between; padding: .7rem 0; border-bottom: 1px solid var(--line); }
    @media (max-width: 900px) { .detail { grid-template-columns: 1fr; } .info { position: static; } }
@endpush

@section('body')
    @php $v = $style->currentVersion; $images = $v?->images ?? collect(); @endphp
    <section style="padding-top:48px">
        <div class="wrap">
            <p style="margin-bottom:1.2rem"><a href="{{ $base }}/collection" class="muted" style="text-decoration:none">← Collection</a></p>
            <div class="detail">
                <div class="gallery">
                    @forelse($images->take(5) as $img)
                        <div class="pic"><img src="{{ $img->url }}" alt="{{ $img->caption ?? $style->name }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}"></div>
                    @empty
                        <div class="pic">@include('sites.partials-pic', ['src' => null])</div>
                    @endforelse
                </div>
                <div class="info">
                    <p class="muted">{{ $style->category?->name }}</p>
                    <h1 style="font-size:clamp(2.2rem,4vw,3.4rem)">{{ $style->name }}</h1>
                    @if(($site['show_prices'] ?? true) && $v?->base_price > 0)<p class="price">From {{ \App\Support\Money::format($v->base_price) }}</p>@endif
                    @if($v?->description)<p style="white-space:pre-line">{{ $v->description }}</p>@endif
                    @if(! empty($v?->price_options))
                        <ul class="opts">@foreach($v->price_options as $o)<li><span>{{ $o['label'] }}</span>@if($site['show_prices'] ?? true)<span class="muted">+{{ \App\Support\Money::format($o['amount']) }}</span>@endif</li>@endforeach</ul>
                    @endif
                    @if($v?->lead_time_days)<p class="muted" style="margin-bottom:1.4rem">Usually ready in about {{ $v->lead_time_days }} days.</p>@endif
                    <a href="{{ $base }}/contact?style={{ $style->public_id }}{{ $full ? '&type=booking' : '' }}" class="btn" style="width:100%">{{ $full ? 'Book a fitting for this style' : 'Ask about this style' }}</a>
                </div>
            </div>
        </div>
    </section>
    @if($more->isNotEmpty())
    <section style="padding-top:0">
        <div class="wrap">
            <h2 style="margin-bottom:28px">You may also like</h2>
            <div class="grid">
                @foreach($more as $s)
                    <a class="card" href="{{ $base }}/collection/{{ $s->public_id }}"><div class="pic">@include('sites.partials-pic', ['src' => $s->currentVersion?->cover()?->url, 'alt' => $s->name])</div><h3>{{ $s->name }}</h3></a>
                @endforeach
            </div>
        </div>
    </section>
    @endif
@endsection
