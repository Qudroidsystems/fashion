@extends('sites.layout')
@section('title', $style->name)

@section('body')
    @php $v = $style->currentVersion; $images = $v?->images ?? collect(); $showPrices = $site['show_prices'] ?? true; @endphp
    <div class="tf-breadcrumb"><div class="container"><div class="tf-breadcrumb-wrap"><div class="tf-breadcrumb-list">
        <a href="{{ $base ?: '/' }}" class="text text-caption-1">Home</a><i class="icon icon-arrRight"></i>
        <a href="{{ $base }}/collection" class="text text-caption-1">Collection</a><i class="icon icon-arrRight"></i>
        <span class="text text-caption-1">{{ $style->name }}</span>
    </div></div></div></div>
    <section class="flat-spacing">
        <div class="container">
            <div class="row g-5">
                <div class="col-md-6">
                    <div class="store-style-gallery">
                        @forelse($images->take(5) as $img)
                            <div class="pic"><img src="{{ $img->url }}" alt="{{ $img->caption ?? $style->name }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}"></div>
                        @empty
                            <div class="pic">@include('sites.partials-pic', ['src' => null])</div>
                        @endforelse
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="tf-product-info-list store-sticky">
                        @if($style->category)<div class="text text-btn-uppercase text-secondary">{{ $style->category->name }}</div>@endif
                        <h3 class="mb-2">{{ $style->name }}</h3>
                        @if($showPrices && $v?->base_price > 0)<h5 class="mb-3">From {{ \App\Support\Money::format($v->base_price) }}</h5>@endif
                        @if($v?->description)<p class="text-secondary" style="white-space:pre-line">{{ $v->description }}</p>@endif
                        @if(! empty($v?->price_options))
                            <h6 class="mt-4">Add-ons</h6>
                            <ul class="store-opts">@foreach($v->price_options as $o)<li><span>{{ $o['label'] }}</span>@if($showPrices)<span class="text-secondary">+{{ \App\Support\Money::format($o['amount']) }}</span>@endif</li>@endforeach</ul>
                        @endif
                        @if($v?->lead_time_days)<p class="text-caption-1 mt-3"><i class="icon icon-timer"></i> Usually ready in about {{ $v->lead_time_days }} days.</p>@endif
                        <a href="{{ $base }}/contact?style={{ $style->public_id }}{{ $full ? '&type=booking' : '' }}" class="tf-btn btn-fill w-100 mt-3"><span class="text">{{ $full ? 'Book a fitting for this style' : 'Ask about this style' }}</span></a>
                        @if($waNumber)
                            <a href="https://wa.me/{{ $waNumber }}?text={{ rawurlencode('Hello ' . $house->name . ', I am interested in the ' . $style->name . ' style.') }}" target="_blank" rel="noopener" class="store-wa-btn mt-2"><i class="icon icon-whatsapp"></i> Ask on WhatsApp</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
    @if($more->isNotEmpty())
    <section class="flat-spacing pt-0">
        <div class="container">
            <div class="heading-section text-center"><h3 class="heading">You may also like</h3></div>
            <div class="tf-grid-layout tf-col-2 lg-col-4">
                @foreach($more as $s)
                    <a class="store-style-card" href="{{ $base }}/collection/{{ $s->public_id }}"><div class="pic">@include('sites.partials-pic', ['src' => $s->currentVersion?->cover()?->url, 'alt' => $s->name])</div><h6 class="mt-3">{{ $s->name }}</h6></a>
                @endforeach
            </div>
        </div>
    </section>
    @endif
@endsection
