@extends('sites.layout')
@section('title', 'Collection')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Collection', 'subtitle' => 'Every piece is made to your measurements. Choose a style to see the details, or tell us what you would like changed.'])
    <section class="flat-spacing">
        <div class="container">
            @if($styles->isEmpty())
                <div class="store-empty"><h5>The collection is being updated</h5><p class="text-secondary"><a href="{{ $base }}/contact" class="link">Get in touch</a> to talk about what you would like made.</p></div>
            @else
            <div class="tf-grid-layout tf-col-2 lg-col-3">
                @foreach($styles as $s)
                    <a class="store-style-card" href="{{ $base }}/collection/{{ $s->public_id }}">
                        <div class="pic">@include('sites.partials-pic', ['src' => $s->currentVersion?->cover()?->url, 'alt' => $s->name])</div>
                        <h6 class="mt-3">{{ $s->name }}</h6>
                        <div class="text-secondary text-caption-1">{{ $s->category?->name }}@if(($site['show_prices'] ?? true) && $s->currentVersion?->base_price > 0){{ $s->category ? ' · ' : '' }}From {{ \App\Support\Money::format($s->currentVersion->base_price) }}@endif</div>
                    </a>
                @endforeach
            </div>
            @endif
        </div>
    </section>
@endsection
