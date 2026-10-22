@extends('sites.layout')

@section('body')
    @php
        $showPrices = $site['show_prices'] ?? true;
        $hero = ! empty($site['hero_path']) ? asset('storage/' . $site['hero_path']) : ($featured->first()?->currentVersion?->cover()?->url ?? $products->first()?->cover_url);
        $tabs = collect(['new' => ['New arrivals', $newest], 'best' => ['Best sellers', $bestSellers], 'sale' => ['On sale', $onSale]])->filter(fn ($t) => $t[1]->isNotEmpty());
    @endphp

    {{-- Hero: the house's slides, or its headline and picture --}}
    @if($slides->isNotEmpty())
        <section class="tf-slideshow slider-default slider-effect-fade">
            <div dir="ltr" class="swiper tf-sw-slideshow" data-effect="fade" data-preview="1" data-tablet="1" data-mobile="1" data-centered="false" data-space="0" data-space-mb="0" data-loop="{{ $slides->count() > 1 ? 'true' : 'false' }}" data-auto-play="true">
                <div class="swiper-wrapper">
                    @foreach($slides as $s)
                        <div class="swiper-slide">
                            <div class="wrap-slider">
                                <img src="{{ $s->image_url }}" alt="{{ $s->title }}">
                                <div class="box-content">
                                    <div class="content-slider">
                                        <div class="box-title-slider">
                                            @if($s->eyebrow)<p class="fade-item fade-item-1 subheading text-btn-uppercase {{ $s->text_colour === 'light' ? 'text-white' : '' }}">{{ $s->eyebrow }}</p>@endif
                                            <div class="fade-item fade-item-2 heading title-display {{ $s->text_colour === 'light' ? 'text-white' : '' }}">{{ $s->title }}</div>
                                            @if($s->subtitle)<p class="fade-item fade-item-2 body-text-1 {{ $s->text_colour === 'light' ? 'text-white' : '' }}">{{ $s->subtitle }}</p>@endif
                                        </div>
                                        <div class="fade-item fade-item-3 box-btn-slider">
                                            <a href="{{ $s->link ? (str_starts_with($s->link, 'http') ? $s->link : $base . '/' . ltrim($s->link, '/')) : $base . '/shop' }}" class="tf-btn btn-fill {{ $s->text_colour === 'light' ? 'btn-white' : '' }}"><span class="text">{{ $s->button_text ?: 'Shop now' }}</span><i class="icon icon-arrowUpRight"></i></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="wrap-pagination"><div class="container"><div class="sw-dots sw-pagination-slider type-circle white-circle justify-content-center"></div></div></div>
        </section>
    @else
        <section class="store-hero">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-6">
                        <h1 class="store-hero-title">{{ $site['headline'] ?? $house->name }}</h1>
                        <p class="store-hero-lead">{{ $site['tagline'] ?? 'Made-to-measure clothing, cut and sewn for you.' }}</p>
                        <div class="d-flex gap-3 flex-wrap">
                            @if($shopOpen)
                                <a href="{{ $base }}/shop" class="tf-btn btn-fill"><span class="text">Shop now</span><i class="icon icon-arrowUpRight"></i></a>
                                <a href="{{ $base }}/collection" class="tf-btn btn-white has-border"><span class="text">See the collection</span></a>
                            @else
                                <a href="{{ $base }}/collection" class="tf-btn btn-fill"><span class="text">See the collection</span><i class="icon icon-arrowUpRight"></i></a>
                                <a href="{{ $base }}/contact{{ $full ? '?type=booking' : '' }}" class="tf-btn btn-white has-border"><span class="text">{{ $full ? 'Book a fitting' : 'Make an enquiry' }}</span></a>
                            @endif
                        </div>
                    </div>
                    <div class="col-lg-6"><div class="store-hero-art">@include('sites.partials-pic', ['src' => $hero, 'alt' => $house->name])</div></div>
                </div>
            </div>
        </section>
    @endif

    {{-- Categories --}}
    @if($categories->count() > 1)
    <section class="flat-spacing-2 pb_0">
        <div class="container">
            <div class="heading-section-2"><h3>Shop by category</h3><a href="{{ $base }}/shop" class="btn-line">See everything</a></div>
            <div class="flat-collection-circle">
                <div dir="ltr" class="swiper tf-sw-collection" data-preview="5" data-tablet="3" data-mobile="2" data-space-lg="20" data-space-md="20" data-space="15" data-pagination="1" data-pagination-md="1" data-pagination-lg="1">
                    <div class="swiper-wrapper">
                        @foreach($categories as $c)
                            <div class="swiper-slide">
                                <div class="collection-circle hover-img">
                                    <a href="{{ $base }}/shop?category={{ $c->id }}" class="img-style">@include('sites.partials-pic', ['src' => $c->cover, 'alt' => $c->name])</a>
                                    <div class="collection-content text-center">
                                        <div><a href="{{ $base }}/shop?category={{ $c->id }}" class="cls-title"><h6 class="text">{{ $c->name }}</h6><i class="icon icon-arrowUpRight"></i></a></div>
                                        <div class="count text-secondary">{{ $c->shop_count }} {{ \Illuminate\Support\Str::plural('item', $c->shop_count) }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex d-lg-none sw-pagination-collection sw-dots type-circle justify-content-center"></div>
                </div>
                <div class="nav-prev-collection d-none d-lg-flex nav-sw style-line nav-sw-left"><i class="icon icon-arrLeft"></i></div>
                <div class="nav-next-collection d-none d-lg-flex nav-sw style-line nav-sw-right"><i class="icon icon-arrRight"></i></div>
            </div>
        </div>
    </section>
    @endif

    {{-- Products --}}
    @if($tabs->isNotEmpty())
    <section class="flat-spacing-3">
        <div class="container">
            <div class="heading-section text-center"><h3 class="heading">{{ $shopOpen ? 'Shop the latest' : 'Ready to wear' }}</h3>
                @unless($shopOpen)<p class="subheading text-secondary">Pieces you can take home today. Ask us to hold one for you.</p>@endunless</div>
            <div class="flat-animate-tab">
                @if($tabs->count() > 1)
                <ul class="tab-product justify-content-sm-center" role="tablist">
                    @foreach($tabs as $k => $t)<li class="nav-tab-item" role="presentation"><a href="#tab-{{ $k }}" class="{{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab">{{ $t[0] }}</a></li>@endforeach
                </ul>
                @endif
                <div class="tab-content">
                    @foreach($tabs as $k => $t)
                        <div class="tab-pane {{ $loop->first ? 'active show' : '' }}" id="tab-{{ $k }}" role="tabpanel">
                            <div class="tf-grid-layout tf-col-2 lg-col-3 xl-col-4">
                                @foreach($t[1] as $p)@include('sites.partials.product-card', ['p' => $p])@endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="text-center mt-4"><a href="{{ $base }}/shop" class="tf-btn btn-fill"><span class="text">{{ $shopOpen ? 'Shop all' : 'See all pieces' }}</span></a></div>
            </div>
        </div>
    </section>
    @endif

    {{-- Promotion banners --}}
    @if($promos->isNotEmpty())
    <section class="flat-spacing pt-0">
        <div class="container">
            <div class="row g-4">
                @foreach($promos as $b)
                    <div class="col-md-{{ $promos->count() > 1 ? 6 : 12 }}">
                        <a href="{{ $b->link ? (str_starts_with($b->link, 'http') ? $b->link : $base . '/' . ltrim($b->link, '/')) : $base . '/shop' }}" class="store-promo {{ $b->text_colour }}">
                            <img src="{{ $b->image_url }}" alt="{{ $b->title }}" loading="lazy">
                            <div class="store-promo-body">
                                @if($b->eyebrow)<p class="text-btn-uppercase">{{ $b->eyebrow }}</p>@endif
                                <h3>{{ $b->title }}</h3>
                                @if($b->subtitle)<p>{{ $b->subtitle }}</p>@endif
                                <span class="tf-btn-default">{{ $b->button_text ?: 'Shop now' }} <i class="icon-arrowUpRight"></i></span>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- Made to measure --}}
    @if($featured->isNotEmpty())
    <section class="flat-spacing pt-0">
        <div class="container">
            <div class="heading-section-2"><h3>From the collection</h3><a href="{{ $base }}/collection" class="btn-line">See everything</a></div>
            <p class="text-secondary mb_24">Made to your measurements — choose a style and we will cut and sew it for you.</p>
            <div class="tf-grid-layout tf-col-2 lg-col-3">
                @foreach($featured as $s)
                    <a class="store-style-card" href="{{ $base }}/collection/{{ $s->public_id }}">
                        <div class="pic">@include('sites.partials-pic', ['src' => $s->currentVersion?->cover()?->url, 'alt' => $s->name])</div>
                        <h6 class="mt-3">{{ $s->name }}</h6>
                        @if($showPrices && $s->currentVersion?->base_price > 0)<div class="text-secondary">From {{ \App\Support\Money::format($s->currentVersion->base_price) }}</div>@endif
                    </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- About / how it works --}}
    <section class="flat-spacing bg-surface">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-5"><h3>{{ ! empty($site['about']) ? 'About ' . $house->name : 'How we work' }}</h3></div>
                <div class="col-lg-7">
                    @if(! empty($site['about']))<p class="body-text-1" style="white-space:pre-line">{{ $site['about'] }}</p>@endif
                    <ol class="store-steps">
                        <li>Tell us what you have in mind, or pick a style from the collection.</li>
                        <li>We take your measurements{{ $full ? ' — in the studio, or you send them to us' : '' }}.</li>
                        <li>Your garment is cut and sewn, with fittings until it is right.</li>
                        <li>Collect it, or we deliver it to you.</li>
                    </ol>
                    <a href="{{ $base }}/contact{{ $full ? '?type=booking' : '' }}" class="tf-btn btn-fill mt-3"><span class="text">{{ $full ? 'Book a fitting' : 'Make an enquiry' }}</span></a>
                </div>
            </div>
        </div>
    </section>

    @if($shopOpen)
    <section class="flat-spacing">
        <div class="container">
            <div dir="ltr" class="swiper tf-sw-iconbox" data-preview="4" data-tablet="3" data-mobile-sm="2" data-mobile="1" data-space-lg="30" data-space-md="30" data-space="15" data-pagination="1" data-pagination-sm="2" data-pagination-md="3" data-pagination-lg="4">
                <div class="swiper-wrapper">
                    <div class="swiper-slide"><div class="tf-icon-box"><div class="icon-box"><span class="icon icon-shipping"></span></div><div class="content text-center"><h6>Delivery or pickup</h6><p class="text-secondary">To your door, or collect from {{ $branches->count() > 1 ? 'one of our branches' : 'our studio' }}.</p></div></div></div>
                    <div class="swiper-slide"><div class="tf-icon-box"><div class="icon-box"><span class="icon icon-security"></span></div><div class="content text-center"><h6>Secure payment</h6><p class="text-secondary">Card, bank transfer or pay on WhatsApp.</p></div></div></div>
                    <div class="swiper-slide"><div class="tf-icon-box"><div class="icon-box"><span class="icon icon-tshirt"></span></div><div class="content text-center"><h6>Made to measure</h6><p class="text-secondary">Any style, cut and sewn to your size.</p></div></div></div>
                    <div class="swiper-slide"><div class="tf-icon-box"><div class="icon-box"><span class="icon icon-whatsapp"></span></div><div class="content text-center"><h6>Talk to a person</h6><p class="text-secondary">Questions? Message us any time.</p></div></div></div>
                </div>
                <div class="sw-pagination-iconbox sw-dots type-circle justify-content-center"></div>
            </div>
        </div>
    </section>
    @endif
@endsection
