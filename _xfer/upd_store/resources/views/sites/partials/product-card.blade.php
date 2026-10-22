{{-- One product in a grid. Needs $p, $base, $site, $shopOpen, $wishlistIds. --}}
@php
    $showPrice = $site['show_prices'] ?? true;
    $vs = $p->variants;
    $min = (float) $vs->min('price');
    $max = (float) $vs->max('price');
    $was = $vs->filter(fn ($v) => $v->isOnSale())->sortBy('price')->first();
    $sale = $p->salePercent();
    $sizes = $vs->pluck('size')->filter()->unique()->values();
    $colours = $vs->filter(fn ($v) => $v->colour)->unique(fn ($v) => mb_strtolower($v->colour))->values();
    $single = $vs->count() === 1 ? $vs->first() : null;
    $url = $base . '/product/' . $p->slug;
    $saved = in_array($p->id, $wishlistIds ?? [], true);
@endphp
<div class="card-product {{ $sizes->isNotEmpty() ? 'card-product-size' : '' }}">
    <div class="card-product-wrapper">
        <a href="{{ $url }}" class="product-img">
            @if($p->cover_url)
                <img class="lazyload img-product" data-src="{{ $p->cover_url }}" src="{{ $p->cover_url }}" alt="{{ $p->name }}" loading="lazy">
                <img class="lazyload img-hover" data-src="{{ $p->hover_url ?: $p->cover_url }}" src="{{ $p->hover_url ?: $p->cover_url }}" alt="" loading="lazy">
            @else
                <div class="store-ph img-product">@include('sites.partials-pic', ['src' => null])</div>
            @endif
        </a>
        @if($sale > 0)<div class="on-sale-wrap"><span class="on-sale-item">-{{ $sale }}%</span></div>
        @elseif($p->badge)<div class="on-sale-wrap"><span class="on-sale-item store-badge">{{ $p->badge }}</span></div>@endif
        @if($sizes->isNotEmpty())
            <div class="variant-wrap size-list"><ul class="variant-box">@foreach($sizes->take(6) as $s)<li class="size-item">{{ $s }}</li>@endforeach</ul></div>
        @endif
        @if($shopOpen)
        <div class="list-product-btn">
            <a href="javascript:void(0);" class="box-icon wishlist btn-icon-action js-wish {{ $saved ? 'active' : '' }}" data-product="{{ $p->public_id }}" aria-label="Save to wishlist">
                <span class="icon icon-heart"></span><span class="tooltip">{{ $saved ? 'Saved' : 'Wishlist' }}</span>
            </a>
            <a href="{{ $url }}" class="box-icon quickview" aria-label="View"><span class="icon icon-eye"></span><span class="tooltip">View</span></a>
        </div>
        <div class="list-btn-main">
            @if($single)
                <a href="javascript:void(0);" class="btn-main-product js-add" data-variant="{{ $single->id }}">Add to basket</a>
            @else
                <a href="{{ $url }}" class="btn-main-product">Choose options</a>
            @endif
        </div>
        @endif
    </div>
    <div class="card-product-info">
        <a href="{{ $url }}" class="title link">{{ $p->name }}</a>
        @if($showPrice)
            <span class="price">
                @if($was && $min == (float) $was->price)<span class="old-price">{{ \App\Support\Money::format($was->compare_at_price) }}</span>@endif
                {{ $min == $max ? \App\Support\Money::format($min) : 'From ' . \App\Support\Money::format($min) }}
            </span>
        @endif
        @if($p->rating_count > 0)
            <div class="store-stars" aria-label="Rated {{ $p->rating_avg }} out of 5">@for($i = 1; $i <= 5; $i++)<i class="icon icon-star {{ $i <= $p->rating_stars ? '' : 'off' }}"></i>@endfor <span>({{ $p->rating_count }})</span></div>
        @endif
        @if($colours->count() > 1)
            <ul class="list-color-product">
                @foreach($colours->take(5) as $c)
                    <li class="list-color-item color-swatch" title="{{ $c->colour }}"><span class="swatch-value" style="background:{{ $c->colour_hex ?: \App\Services\Store\StoreCatalog::guessHex($c->colour) }}"></span></li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
