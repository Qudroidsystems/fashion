@extends('sites.layout')
@section('title', $product->seo_title ?: $product->name)
@section('description', $product->seo_description ?: \Illuminate\Support\Str::limit(strip_tags((string) $product->description) ?: $product->name, 155))
@section('og_type', 'product')
@if($product->cover_url)@section('og_image', $product->cover_url)@endif

@section('body')
    @php
        use App\Support\Money;
        $showPrice = $site['show_prices'] ?? true;
        $gallery = $product->images->isNotEmpty() ? $product->images : collect($product->galleryUrls())->map(fn ($u) => (object) ['url' => $u, 'alt' => $product->name, 'colour' => null]);
        $colours = $product->variants->filter(fn ($v) => $v->colour)->unique(fn ($v) => mb_strtolower($v->colour))->values();
        $sizes = $product->variants->pluck('size')->filter()->unique()->values();
        $f = $first;
        $fStock = $stock[$f->id] ?? null;
        $waOn = ! $shopOpen || ! empty($shop['methods']['whatsapp']);
        $waLabel = $shop['whatsapp']['button_label'] ?? 'Order on WhatsApp';
    @endphp

    <div class="tf-breadcrumb">
        <div class="container">
            <div class="tf-breadcrumb-wrap">
                <div class="tf-breadcrumb-list">
                    <a href="{{ $base ?: '/' }}" class="text text-caption-1">Home</a><i class="icon icon-arrRight"></i>
                    <a href="{{ $base }}/shop" class="text text-caption-1">Shop</a><i class="icon icon-arrRight"></i>
                    @if($product->category)<a href="{{ $base }}/shop?category={{ $product->category_id }}" class="text text-caption-1">{{ $product->category->name }}</a><i class="icon icon-arrRight"></i>@endif
                    <span class="text text-caption-1">{{ $product->name }}</span>
                </div>
            </div>
        </div>
    </div>

    <section class="flat-spacing">
        <div class="tf-main-product section-image-zoom">
            <div class="container">
                <div class="row">
                    <div class="col-md-6">
                        <div class="tf-product-media-wrap sticky-top">
                            @if($gallery->isEmpty())
                                <div class="store-ph store-ph-lg">@include('sites.partials-pic', ['src' => null])</div>
                            @else
                            <div class="thumbs-slider store-gallery">
                                <div dir="ltr" class="swiper tf-product-media-thumbs other-image-zoom js-thumbs" data-direction="vertical">
                                    <div class="swiper-wrapper">
                                        @foreach($gallery as $img)<div class="swiper-slide" data-colour="{{ $img->colour }}"><div class="item"><img src="{{ $img->url }}" alt="{{ $img->alt ?? $product->name }}"></div></div>@endforeach
                                    </div>
                                </div>
                                <div dir="ltr" class="swiper tf-product-media-main js-main">
                                    <div class="swiper-wrapper">
                                        @foreach($gallery as $img)
                                            <div class="swiper-slide" data-colour="{{ $img->colour }}"><a href="{{ $img->url }}" target="_blank" class="item"><img class="tf-image-zoom" src="{{ $img->url }}" alt="{{ $img->alt ?? $product->name }}" @if(! $loop->first) loading="lazy" @endif></a></div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="tf-product-info-wrap position-relative">
                            <div class="tf-product-info-list store-product-info js-product" data-variants='@json($variantJson)' data-currency="{{ Money::symbol() }}" data-low="{{ $lowStock }}">
                                <div class="tf-product-info-heading">
                                    <div class="tf-product-info-name">
                                        @if($product->category)<div class="text text-btn-uppercase">{{ $product->category->name }}</div>@endif
                                        <h3 class="name">{{ $product->name }}</h3>
                                        <div class="sub">
                                            @if($product->rating_count > 0)
                                            <div class="tf-product-info-rate">
                                                <div class="list-star">@for($i = 1; $i <= 5; $i++)<i class="icon icon-star {{ $i <= $product->rating_stars ? '' : 'off' }}"></i>@endfor</div>
                                                <a href="#reviews" class="text text-caption-1">({{ $product->rating_count }} {{ \Illuminate\Support\Str::plural('review', $product->rating_count) }})</a>
                                            </div>
                                            @endif
                                            @if($product->sold_count >= 5)<div class="tf-product-info-sold"><i class="icon icon-lightning"></i><div class="text text-caption-1">{{ $product->sold_count }} sold</div></div>@endif
                                        </div>
                                    </div>
                                    @if($showPrice)
                                    <div class="tf-product-info-desc">
                                        <div class="tf-product-info-price">
                                            <h5 class="price-on-sale font-2 js-price" data-base-price="{{ (float) $f->price }}">{{ Money::format($f->price) }}</h5>
                                            <div class="compare-at-price font-2 js-compare {{ $f->isOnSale() ? '' : 'd-none' }}">{{ $f->isOnSale() ? Money::format($f->compare_at_price) : '' }}</div>
                                            <div class="badges-on-sale text-btn-uppercase js-off {{ $f->isOnSale() ? '' : 'd-none' }}">{{ $f->isOnSale() ? '-' . round(((float) $f->compare_at_price - (float) $f->price) / (float) $f->compare_at_price * 100) . '%' : '' }}</div>
                                        </div>
                                    </div>
                                    @endif
                                    @if($product->description)<p class="text-secondary" style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($product->description, 400) }}</p>@endif
                                </div>

                                <form class="tf-product-info-choose-option js-add-form" method="POST" action="{{ $base }}/cart/add">
                                    @csrf
                                    <input type="hidden" name="variant_id" value="{{ $f->id }}" class="js-variant-id">
                                    @if($colours->isNotEmpty())
                                    <div class="variant-picker-item">
                                        <div class="variant-picker-label mb_12">Colour: <span class="text-title variant-picker-label-value js-colour-label">{{ $f->colour }}</span></div>
                                        <div class="variant-picker-values">
                                            @foreach($colours as $c)
                                                <input id="c-{{ $loop->index }}" type="radio" name="_colour" value="{{ $c->colour }}" @checked(mb_strtolower((string) $f->colour) === mb_strtolower($c->colour))>
                                                <label class="hover-tooltip tooltip-bot radius-60 color-btn js-colour" for="c-{{ $loop->index }}" data-value="{{ $c->colour }}">
                                                    <span class="btn-checkbox" style="background:{{ $c->colour_hex ?: \App\Services\Store\StoreCatalog::guessHex($c->colour) }}"></span><span class="tooltip">{{ $c->colour }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                    @endif
                                    @if($sizes->isNotEmpty())
                                    <div class="variant-picker-item">
                                        <div class="d-flex justify-content-between mb_12">
                                            <div class="variant-picker-label">Size: <span class="text-title variant-picker-label-value js-size-label">{{ $f->size }}</span></div>
                                            @if(! empty($shop['size_guide']))<a href="{{ $base }}/pages/size-guide" class="size-guide text-title link" target="_blank">Size guide</a>@endif
                                        </div>
                                        <div class="variant-picker-values gap12">
                                            @foreach($sizes as $s)
                                                <input type="radio" name="_size" id="s-{{ $loop->index }}" value="{{ $s }}" @checked($f->size === $s)>
                                                <label class="style-text size-btn js-size" for="s-{{ $loop->index }}" data-value="{{ $s }}"><span class="text-title">{{ $s }}</span></label>
                                            @endforeach
                                        </div>
                                    </div>
                                    @endif

                                    <p class="text-caption-1 js-stock store-stock" aria-live="polite">
                                        @if($fStock === null) In stock @elseif($fStock <= 0) <span class="text-critical">Sold out</span> @elseif($fStock <= $lowStock) <span class="text-warning-2">Only {{ $fStock }} left</span> @else In stock @endif
                                    </p>

                                    @if($shopOpen)
                                    <div class="tf-product-info-quantity">
                                        <div class="title mb_12">Quantity:</div>
                                        <div class="wg-quantity">
                                            <span class="btn-quantity js-qty-dec" role="button" aria-label="Less">-</span>
                                            <input class="js-qty" type="number" name="quantity" value="1" min="1" max="99" aria-label="Quantity">
                                            <span class="btn-quantity js-qty-inc" role="button" aria-label="More">+</span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="tf-product-info-by-btn mb_10">
                                            <button type="submit" class="btn-style-2 flex-grow-1 text-btn-uppercase fw-6 js-add-btn" {{ $fStock !== null && $fStock <= 0 ? 'disabled' : '' }}><span>Add to basket</span></button>
                                            <a href="javascript:void(0);" class="box-icon hover-tooltip text-caption-2 wishlist btn-icon-action js-wish {{ in_array($product->id, $wishlistIds, true) ? 'active' : '' }}" data-product="{{ $product->public_id }}" aria-label="Save to wishlist">
                                                <span class="icon icon-heart"></span><span class="tooltip text-caption-2">Wishlist</span>
                                            </a>
                                        </div>
                                        <button type="submit" name="buy_now" value="1" class="btn-style-3 text-btn-uppercase w-100 js-buy-now" {{ $fStock !== null && $fStock <= 0 ? 'disabled' : '' }}>Buy it now</button>
                                    </div>
                                    @endif
                                    @if($waOn && $waProduct)
                                        <a href="{{ $waProduct }}" target="_blank" rel="noopener" class="store-wa-btn js-wa-product mt-2"><i class="icon icon-whatsapp"></i> {{ $waLabel }}</a>
                                    @endif
                                    @unless($shopOpen)
                                        <a href="{{ $base }}/contact?product={{ $product->public_id }}" class="tf-btn btn-fill w-100 mt-2"><span class="text">Ask to hold this</span></a>
                                    @endunless
                                </form>

                                <div class="tf-product-info-help">
                                    <div class="tf-product-info-extra-link">
                                        @if(! empty($shop['policies']['shipping']))<a href="{{ $base }}/pages/shipping" class="tf-product-extra-icon"><div class="icon"><i class="icon-shipping"></i></div><p class="text-caption-1">Delivery</p></a>@endif
                                        @if(! empty($shop['policies']['returns']))<a href="{{ $base }}/pages/returns" class="tf-product-extra-icon"><div class="icon"><i class="icon-arrowClockwise"></i></div><p class="text-caption-1">Returns</p></a>@endif
                                        <a href="{{ $base }}/contact?product={{ $product->public_id }}" class="tf-product-extra-icon"><div class="icon"><i class="icon-question"></i></div><p class="text-caption-1">Ask a question</p></a>
                                        <a href="https://wa.me/?text={{ rawurlencode($product->name . ' — ' . $shareUrl) }}" target="_blank" rel="noopener" class="tf-product-extra-icon"><div class="icon"><i class="icon-share"></i></div><p class="text-caption-1">Share</p></a>
                                    </div>
                                    @if($shopOpen && ($shop['pickup']['enabled'] ?? false) && $branches->isNotEmpty())
                                        <div class="tf-product-info-time"><div class="icon"><i class="icon-map-pin"></i></div><p class="text-caption-1">Pickup available at {{ $branches->first()->name }}. {{ $shop['pickup']['note'] ?? '' }}</p></div>
                                    @endif
                                </div>
                                <ul class="tf-product-info-sku">
                                    <li><p class="text-caption-1">SKU:</p><p class="text-caption-1 text-1 js-sku">{{ $f->sku }}</p></li>
                                    @if($product->category)<li><p class="text-caption-1">Category:</p><p class="text-caption-1"><a href="{{ $base }}/shop?category={{ $product->category_id }}" class="text-1 link">{{ $product->category->name }}</a></p></li>@endif
                                    @if($product->tags)<li><p class="text-caption-1">Tags:</p><p class="text-caption-1 text-1">{{ implode(', ', $product->tags) }}</p></li>@endif
                                </ul>
                                @if($product->style)
                                    <p class="text-caption-1 mt-3">Want it made to your measurements? <a href="{{ $base }}/collection/{{ $product->style->public_id }}" class="link fw-6">See the {{ $product->style->name }} style</a></p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="flat-spacing pt-0">
        <div class="container">
            <div class="widget-tabs style-1">
                <ul class="widget-menu-tab">
                    <li class="item-title active"><span class="inner">Description</span></li>
                    @if($product->details)<li class="item-title"><span class="inner">Fabric &amp; care</span></li>@endif
                    <li class="item-title" id="reviews"><span class="inner">Reviews ({{ $product->rating_count }})</span></li>
                    @if(! empty($shop['policies']['shipping']) || ! empty($shop['policies']['returns']))<li class="item-title"><span class="inner">Delivery &amp; returns</span></li>@endif
                </ul>
                <div class="widget-content-tab">
                    <div class="widget-content-inner active"><div class="tab-description" style="white-space:pre-line">{{ $product->description ?: 'No description yet.' }}</div></div>
                    @if($product->details)<div class="widget-content-inner"><div style="white-space:pre-line">{{ $product->details }}</div></div>@endif
                    <div class="widget-content-inner">
                        <div class="row g-5">
                            <div class="col-lg-7">
                                @forelse($reviews as $r)
                                    <div class="store-review">
                                        <div class="list-star">@for($i = 1; $i <= 5; $i++)<i class="icon icon-star {{ $i <= $r->rating ? '' : 'off' }}"></i>@endfor</div>
                                        @if($r->title)<h6 class="mt-1">{{ $r->title }}</h6>@endif
                                        <p>{{ $r->body }}</p>
                                        <p class="text-caption-1 text-secondary">{{ $r->name }}@if($r->verified_buyer) · <span class="text-success">Verified buyer</span>@endif · {{ $r->created_at->format('j M Y') }}</p>
                                        @if($r->reply)<div class="store-review-reply"><strong>{{ $house->name }}:</strong> {{ $r->reply }}</div>@endif
                                    </div>
                                @empty
                                    <p class="text-secondary">No reviews yet. Be the first to tell others what you think.</p>
                                @endforelse
                            </div>
                            @if($canReview)
                            <div class="col-lg-5">
                                <form method="POST" action="{{ $base }}/product/{{ $product->slug }}/review" class="store-form">
                                    @csrf
                                    <h6 class="mb-3">Write a review</h6>
                                    <div class="store-rating-input mb-3" role="radiogroup" aria-label="Your rating">
                                        @for($i = 5; $i >= 1; $i--)<input type="radio" id="rate{{ $i }}" name="rating" value="{{ $i }}" @checked(old('rating') == $i)><label for="rate{{ $i }}" title="{{ $i }} stars"><i class="icon icon-star"></i></label>@endfor
                                    </div>
                                    @error('rating')<div class="store-err">Choose a rating.</div>@enderror
                                    <input type="text" name="name" value="{{ old('name', $account?->first_name) }}" placeholder="Your name *" required maxlength="80" class="mb-2">
                                    <input type="text" name="title" value="{{ old('title') }}" placeholder="Headline" maxlength="120" class="mb-2">
                                    <textarea name="body" rows="4" placeholder="What did you like or not like?" required maxlength="3000" class="mb-2">{{ old('body') }}</textarea>
                                    @error('body')<div class="store-err">{{ $message }}</div>@enderror
                                    <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off" aria-hidden="true">
                                    <button class="tf-btn btn-fill"><span class="text">Send review</span></button>
                                    @if($verified)<p class="text-caption-1 text-success mt-2">You bought this — your review will show as a verified buyer.</p>@endif
                                </form>
                            </div>
                            @endif
                        </div>
                    </div>
                    @if(! empty($shop['policies']['shipping']) || ! empty($shop['policies']['returns']))
                    <div class="widget-content-inner">
                        @if(! empty($shop['policies']['shipping']))<h6>Delivery</h6><p style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($shop['policies']['shipping'], 700) }}</p>@endif
                        @if(! empty($shop['policies']['returns']))<h6 class="mt-3">Returns</h6><p style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($shop['policies']['returns'], 700) }}</p>@endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    @if($related->isNotEmpty())
    <section class="flat-spacing pt-0">
        <div class="container">
            <div class="heading-section text-center"><h3 class="heading">You may also like</h3></div>
            <div class="tf-grid-layout tf-col-2 lg-col-3 xl-col-4">
                @foreach($related->take(8) as $p)@include('sites.partials.product-card', ['p' => $p])@endforeach
            </div>
        </div>
    </section>
    @endif
@endsection
