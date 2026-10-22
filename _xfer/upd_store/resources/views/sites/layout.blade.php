{{--
    House website & online shop layout (Modave template in public/ecommerce_website).
    Shared data comes from Sites\StorefrontController::shared().
--}}
@php
    $accent = $house->brand_color ?: '#7A2E4D';
    $name = $house->name;
    $here = '/' . trim(\Illuminate\Support\Str::after(request()->path(), ltrim($base, '/')), '/');
    $here = $here === '/' ? '' : $here;
    $home = $base ?: '/';
    $nav = ['' => 'Home'];
    if ($shopOpen || $hasProducts) { $nav['/shop'] = $shopOpen ? 'Shop' : 'Ready to wear'; }
    $nav['/collection'] = 'Collection';
    if ($shopOpen) { $nav['/track'] = 'Track order'; }
    $nav['/contact'] = 'Contact';
    $policies = collect(['shipping' => 'Delivery information', 'returns' => 'Returns & exchanges', 'terms' => 'Terms & conditions', 'privacy' => 'Privacy policy'])
        ->filter(fn ($l, $k) => ! empty($shop['policies'][$k] ?? null));
    $waText = 'Hello ' . $name . ', I found you on your website.';
    $cur = $house->currency_code ?: 'NGN';
    $mainBranch = $branches->first();
    $hex = ltrim($accent, '#');
    $rgb = strlen($hex) === 6 ? implode(',', array_map('hexdec', str_split($hex, 2))) : '122,46,77';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · {{ $name }}@else{{ $name }} · {{ $site['tagline'] ?? 'Bespoke fashion' }}@endif</title>
    <meta name="description" content="@yield('description', \Illuminate\Support\Str::limit($site['about'] ?? ($site['tagline'] ?? $name), 155))">
    <meta name="theme-color" content="{{ $accent }}">
    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:title" content="@yield('title', $name)">
    <meta property="og:type" content="@yield('og_type', 'website')">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@elseif(! empty($site['hero_path']))<meta property="og:image" content="{{ asset('storage/' . $site['hero_path']) }}">@endif
    @if($house->logo_url)<link rel="icon" href="{{ $house->logo_url }}">@endif
    <link rel="stylesheet" href="{{ $assets }}/fonts/fonts.css">
    <link rel="stylesheet" href="{{ $assets }}/fonts/font-icons.css">
    <link rel="stylesheet" href="{{ $assets }}/css/bootstrap.min.css">
    <link rel="stylesheet" href="{{ $assets }}/css/swiper-bundle.min.css">
    <link rel="stylesheet" href="{{ $assets }}/css/animate.css">
    <link rel="stylesheet" href="{{ $assets }}/css/styles.css">
    <link rel="stylesheet" href="{{ $assets }}/css/store.css">
    <style>
        :root { --primary: {{ $accent }}; --rgba-primary: rgba({{ $rgb }}, .1); --accent: {{ $accent }}; }
        @stack('css')
    </style>
</head>
<body class="preload-wrapper">
    <div class="preload preload-container"><div class="preload-logo"><div class="spinner"></div></div></div>

    <div id="wrapper">
        @if(! empty($shop['announcement']) || $house->phone)
        <div class="tf-topbar bg-main">
            <div class="container">
                <div class="tf-topbar_wrap d-flex align-items-center justify-content-center justify-content-xl-between">
                    <ul class="topbar-left">
                        @if($house->phone)<li><a class="text-caption-1 text-white" href="tel:{{ preg_replace('/[^0-9+]/', '', $house->phone) }}">{{ $house->phone }}</a></li>@endif
                        @if($house->email)<li class="d-none d-md-block"><a class="text-caption-1 text-white" href="mailto:{{ $house->email }}">{{ $house->email }}</a></li>@endif
                    </ul>
                    @if(! empty($shop['announcement']))<p class="text-caption-1 text-white d-none d-xl-block mb-0">{{ $shop['announcement'] }}</p>@endif
                </div>
            </div>
        </div>
        @endif

        <header id="header" class="header-default">
            <div class="container">
                <div class="row wrapper-header align-items-center">
                    <div class="col-md-4 col-3 d-xl-none">
                        <a href="#mobileMenu" class="mobile-menu" data-bs-toggle="offcanvas" aria-controls="mobileMenu" aria-label="Menu"><i class="icon icon-categories"></i></a>
                    </div>
                    <div class="col-xl-3 col-md-4 col-6">
                        <a href="{{ $home }}" class="logo-header store-brand">
                            @if($house->logo_url)<img src="{{ $house->logo_url }}" alt="{{ $name }}" class="logo">@else<span class="store-brand-init">{{ $house->initials }}</span>@endif
                            <span class="store-brand-name">{{ $name }}</span>
                        </a>
                    </div>
                    <div class="col-xl-6 d-none d-xl-block">
                        <nav class="box-navigation text-center" aria-label="Main">
                            <ul class="box-nav-ul d-flex align-items-center justify-content-center">
                                @foreach($nav as $path => $label)
                                    @if($path === '/shop' && count($menuCategories))
                                        <li class="menu-item {{ $here === $path ? 'active' : '' }}">
                                            <a href="{{ $base . $path }}" class="item-link" @if($here === $path) aria-current="page" @endif>{{ $label }}<i class="icon icon-arrow-down"></i></a>
                                            <div class="sub-menu submenu-default">
                                                <ul class="menu-list">
                                                    <li><a href="{{ $base }}/shop" class="menu-link-text">Everything</a></li>
                                                    @foreach($menuCategories as $c)<li><a href="{{ $base }}/shop?category={{ $c->id }}" class="menu-link-text">{{ $c->name }}</a></li>@endforeach
                                                    @if($shopOpen)<li><a href="{{ $base }}/shop?sale=1" class="menu-link-text">On sale</a></li>@endif
                                                </ul>
                                            </div>
                                        </li>
                                    @else
                                        <li class="menu-item {{ $here === $path ? 'active' : '' }}"><a href="{{ $base . $path ?: '/' }}" class="item-link" @if($here === $path) aria-current="page" @endif>{{ $label }}</a></li>
                                    @endif
                                @endforeach
                                <li class="menu-item"><a href="{{ $base }}/contact{{ $full ? '?type=booking' : '' }}" class="item-link store-nav-cta">{{ $full ? 'Book a fitting' : 'Make an enquiry' }}</a></li>
                            </ul>
                        </nav>
                    </div>
                    <div class="col-xl-3 col-md-4 col-3">
                        <ul class="nav-icon d-flex justify-content-end align-items-center">
                            <li class="nav-search"><a href="#search" data-bs-toggle="modal" class="nav-icon-item" aria-label="Search">
                                <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M11 19C15.4183 19 19 15.4183 19 11C19 6.58172 15.4183 3 11 3C6.58172 3 3 6.58172 3 11C3 15.4183 6.58172 19 11 19Z" stroke="#181818" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21.35 21.0004L17 16.6504" stroke="#181818" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a></li>
                            @if($shopOpen)
                            <li class="nav-account">
                                <a href="{{ $base }}/account" class="nav-icon-item" aria-label="My account">
                                    <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="#181818" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 11C14.2091 11 16 9.20914 16 7C16 4.79086 14.2091 3 12 3C9.79086 3 8 4.79086 8 7C8 9.20914 9.79086 11 12 11Z" stroke="#181818" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </a>
                                <div class="dropdown-account dropdown-login">
                                    <div class="sub-top">
                                        @if($account)
                                            <p class="text-center mb-2">Hello, <strong>{{ $account->first_name }}</strong></p>
                                            <a href="{{ $base }}/account/orders" class="tf-btn btn-reset">My orders</a>
                                            <form method="POST" action="{{ $base }}/account/logout" class="text-center mt-2">@csrf<button class="btn btn-link text-secondary-2 p-0">Sign out</button></form>
                                        @else
                                            <a href="{{ $base }}/account/login" class="tf-btn btn-reset">Sign in</a>
                                            <p class="text-center text-secondary-2">New here? <a href="{{ $base }}/account/register">Create an account</a></p>
                                        @endif
                                    </div>
                                    <div class="sub-bot"><a href="{{ $base }}/track" class="body-text-">Track an order</a></div>
                                </div>
                            </li>
                            <li class="nav-wishlist"><a href="{{ $base }}/wishlist" class="nav-icon-item" aria-label="Wishlist">
                                <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M20.8401 4.60987C20.3294 4.09888 19.7229 3.69352 19.0555 3.41696C18.388 3.14039 17.6726 2.99805 16.9501 2.99805C16.2276 2.99805 15.5122 3.14039 14.8448 3.41696C14.1773 3.69352 13.5709 4.09888 13.0601 4.60987L12.0001 5.66987L10.9401 4.60987C9.90843 3.57818 8.50915 2.99858 7.05012 2.99858C5.59109 2.99858 4.19181 3.57818 3.16012 4.60987C2.12843 5.64156 1.54883 7.04084 1.54883 8.49987C1.54883 9.95891 2.12843 11.3582 3.16012 12.3899L4.22012 13.4499L12.0001 21.2299L19.7801 13.4499L20.8401 12.3899C21.3511 11.8791 21.7565 11.2727 22.033 10.6052C22.3096 9.93777 22.4519 9.22236 22.4519 8.49987C22.4519 7.77738 22.3096 7.06198 22.033 6.39452C21.7565 5.72706 21.3511 5.12063 20.8401 4.60987V4.60987Z" stroke="#181818" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span class="count-box js-wish-count {{ count($wishlistIds) ? '' : 'd-none' }}">{{ count($wishlistIds) }}</span>
                            </a></li>
                            <li class="nav-cart"><a href="#shoppingCart" data-bs-toggle="modal" class="nav-icon-item" aria-label="Basket">
                                <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M16.5078 10.8734V6.36686C16.5078 5.17166 16.033 4.02541 15.1879 3.18028C14.3428 2.33514 13.1965 1.86035 12.0013 1.86035C10.8061 1.86035 9.65985 2.33514 8.81472 3.18028C7.96958 4.02541 7.49479 5.17166 7.49479 6.36686V10.8734M4.11491 8.62012H19.8877L21.0143 22.1396H2.98828L4.11491 8.62012Z" stroke="#181818" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span class="count-box js-cart-count">{{ $cartCount }}</span>
                            </a></li>
                            @endif
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        @if(session('toast') || session('toast_error'))
            <div class="store-flash {{ session('toast_error') ? 'is-error' : '' }}" role="status">{{ session('toast_error') ?: session('toast') }}</div>
        @endif

        <main>@yield('body')</main>

        <footer id="footer" class="footer">
            <div class="footer-wrap">
                <div class="footer-body">
                    <div class="container">
                        <div class="row">
                            <div class="col-lg-4">
                                <div class="footer-infor">
                                    <div class="footer-logo"><a href="{{ $home }}" class="store-brand">@if($house->logo_url)<img src="{{ $house->logo_url }}" alt="">@else<span class="store-brand-init">{{ $house->initials }}</span>@endif<span class="store-brand-name">{{ $name }}</span></a></div>
                                    @if(! empty($site['tagline']))<p class="text-secondary mb_12">{{ $site['tagline'] }}</p>@endif
                                    <div class="footer-address">
                                        @foreach($branches->take(3) as $b)<p>{{ $b->name }}{{ $b->address ? ' — ' . $b->address : '' }}{{ $b->city ? ', ' . $b->city : '' }}</p>@endforeach
                                        <a href="{{ $base }}/contact" class="tf-btn-default fw-6">GET IN TOUCH<i class="icon-arrowUpRight"></i></a>
                                    </div>
                                    <ul class="footer-info">
                                        @if($house->email)<li><i class="icon-mail"></i><p><a href="mailto:{{ $house->email }}">{{ $house->email }}</a></p></li>@endif
                                        @if($house->phone)<li><i class="icon-phone"></i><p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $house->phone) }}">{{ $house->phone }}</a></p></li>@endif
                                    </ul>
                                    <ul class="tf-social-icon">
                                        @if(! empty($site['facebook']))<li><a href="https://facebook.com/{{ ltrim($site['facebook'], '@') }}" class="social-facebook" target="_blank" rel="noopener" aria-label="Facebook"><i class="icon icon-fb"></i></a></li>@endif
                                        @if(! empty($site['instagram']))<li><a href="https://instagram.com/{{ ltrim($site['instagram'], '@') }}" class="social-instagram" target="_blank" rel="noopener" aria-label="Instagram"><i class="icon icon-instagram"></i></a></li>@endif
                                        @if(! empty($site['tiktok']))<li><a href="https://tiktok.com/@{{ ltrim($site['tiktok'], '@') }}" class="social-tiktok" target="_blank" rel="noopener" aria-label="TikTok"><i class="icon icon-tiktok"></i></a></li>@endif
                                        @if($waNumber)<li><a href="https://wa.me/{{ $waNumber }}" class="social-whatsapp" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="icon icon-whatsapp"></i></a></li>@endif
                                    </ul>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="footer-menu">
                                    <div class="footer-col-block">
                                        <div class="footer-heading text-button footer-heading-mobile">Shop</div>
                                        <div class="tf-collapse-content">
                                            <ul class="footer-menu-list">
                                                @foreach($nav as $path => $label)<li class="text-caption-1"><a href="{{ $base . $path ?: '/' }}" class="footer-menu_item">{{ $label }}</a></li>@endforeach
                                                @if($shopOpen)<li class="text-caption-1"><a href="{{ $base }}/account" class="footer-menu_item">My account</a></li>
                                                <li class="text-caption-1"><a href="{{ $base }}/wishlist" class="footer-menu_item">My wishlist</a></li>@endif
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="footer-col-block">
                                        <div class="footer-heading text-button footer-heading-mobile">Help</div>
                                        <div class="tf-collapse-content">
                                            <ul class="footer-menu-list">
                                                @foreach($policies as $k => $l)<li class="text-caption-1"><a href="{{ $base }}/pages/{{ $k }}" class="footer-menu_item">{{ $l }}</a></li>@endforeach
                                                @if(! empty($shop['size_guide']))<li class="text-caption-1"><a href="{{ $base }}/pages/size-guide" class="footer-menu_item">Size guide</a></li>@endif
                                                <li class="text-caption-1"><a href="{{ $base }}/contact" class="footer-menu_item">Contact us</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="footer-col-block">
                                    <div class="footer-heading text-button footer-heading-mobile">Visit</div>
                                    <div class="tf-collapse-content">
                                        @if(! empty($site['hours']))<p class="text-caption-1" style="white-space:pre-line">{{ $site['hours'] }}</p>@endif
                                        @if($shopOpen)
                                            <p class="text-caption-1 mt-3">Secure checkout. Pay by card or bank transfer{{ ! empty($shop['methods']['whatsapp']) ? ', or order on WhatsApp' : '' }}.</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="footer-bottom">
                    <div class="container">
                        <div class="footer-bottom-wrap">
                            <div class="left"><p class="text-caption-1">© {{ now()->year }} {{ $name }}. All rights reserved.</p></div>
                            @unless($full)<p class="text-caption-1 text-secondary">Made with {{ $platform }}</p>@endunless
                        </div>
                    </div>
                </div>
            </div>
        </footer>

        <div class="tf-toolbar-bottom">
            <div class="toolbar-item"><a href="{{ $base }}/shop"><div class="toolbar-icon"><i class="icon icon-squares-four"></i></div><div class="toolbar-label">Shop</div></a></div>
            <div class="toolbar-item"><a href="#search" data-bs-toggle="modal"><div class="toolbar-icon"><i class="icon icon-search2"></i></div><div class="toolbar-label">Search</div></a></div>
            @if($shopOpen)
            <div class="toolbar-item"><a href="{{ $base }}/wishlist"><div class="toolbar-icon"><i class="icon icon-heart"></i></div><div class="toolbar-label">Wishlist</div></a></div>
            <div class="toolbar-item"><a href="#shoppingCart" data-bs-toggle="modal"><div class="toolbar-icon"><i class="icon icon-ShoppingBagOpen"></i><div class="toolbar-count js-cart-count">{{ $cartCount }}</div></div><div class="toolbar-label">Basket</div></a></div>
            <div class="toolbar-item"><a href="{{ $base }}/account"><div class="toolbar-icon"><i class="icon icon-user"></i></div><div class="toolbar-label">Account</div></a></div>
            @else
            <div class="toolbar-item"><a href="{{ $base }}/contact"><div class="toolbar-icon"><i class="icon icon-mail"></i></div><div class="toolbar-label">Contact</div></a></div>
            @endif
        </div>
    </div>

    @if($waNumber)
        <a class="store-wa" href="https://wa.me/{{ $waNumber }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="icon icon-whatsapp"></i></a>
    @endif

    {{-- Search --}}
    <div class="modal fade modal-search" id="search">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="d-flex justify-content-between align-items-center"><h5>Search</h5><span class="icon-close icon-close-popup" data-bs-dismiss="modal"></span></div>
                <form class="form-search" action="{{ $base }}/shop" method="GET">
                    <fieldset class="text"><input type="text" placeholder="Dresses, agbada, ankara…" name="q" value="{{ request('q') }}" aria-label="Search the shop" required></fieldset>
                    <button type="submit" aria-label="Search"><i class="icon icon-search2"></i></button>
                </form>
                @if(count($menuCategories))
                <div>
                    <h6 class="mb_16">Browse</h6>
                    <ul class="list-tags">@foreach($menuCategories as $c)<li><a href="{{ $base }}/shop?category={{ $c->id }}" class="radius-60 link">{{ $c->name }}</a></li>@endforeach</ul>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Mobile menu --}}
    <div class="offcanvas offcanvas-start canvas-mb" id="mobileMenu">
        <span class="icon-close icon-close-popup" data-bs-dismiss="offcanvas" aria-label="Close"></span>
        <div class="mb-canvas-content">
            <div class="mb-body">
                <div class="mb-content-top">
                    <form class="form-search" action="{{ $base }}/shop" method="GET">
                        <fieldset class="text"><input type="text" placeholder="What are you looking for?" name="q" aria-label="Search"></fieldset>
                        <button type="submit" aria-label="Search"><i class="icon icon-search2"></i></button>
                    </form>
                    <ul class="nav-ul-mb">
                        @foreach($nav as $path => $label)<li class="nav-mb-item {{ $here === $path ? 'active' : '' }}"><a href="{{ $base . $path ?: '/' }}" class="mb-menu-link">{{ $label }}</a></li>@endforeach
                        @foreach($menuCategories->take(8) as $c)<li class="nav-mb-item"><a href="{{ $base }}/shop?category={{ $c->id }}" class="mb-menu-link ps-3 text-secondary">{{ $c->name }}</a></li>@endforeach
                        <li class="nav-mb-item"><a href="{{ $base }}/contact{{ $full ? '?type=booking' : '' }}" class="mb-menu-link">{{ $full ? 'Book a fitting' : 'Make an enquiry' }}</a></li>
                    </ul>
                </div>
                <div class="mb-other-content">
                    @if($shopOpen)
                    <div class="group-icon">
                        <a href="{{ $base }}/wishlist" class="site-nav-icon"><i class="icon icon-heart"></i> Wishlist</a>
                        <a href="{{ $base }}/account" class="site-nav-icon"><i class="icon icon-user"></i> {{ $account ? 'Account' : 'Sign in' }}</a>
                    </div>
                    @endif
                    <div class="mb-notice"><a href="{{ $base }}/contact" class="text-need">Need help?</a></div>
                    <ul class="mb-info">
                        @if($house->email)<li><i class="icon icon-mail"></i><p>{{ $house->email }}</p></li>@endif
                        @if($house->phone)<li><i class="icon icon-phone"></i><p>{{ $house->phone }}</p></li>@endif
                    </ul>
                </div>
            </div>
        </div>
    </div>

    @if($shopOpen)
    {{-- Mini cart (filled by store.js from /cart/mini) --}}
    <div class="modal fullRight fade modal-shopping-cart" id="shoppingCart">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="d-flex flex-column flex-grow-1 h-100">
                    <div class="header"><h5 class="title">Your basket</h5><span class="icon-close icon-close-popup" data-bs-dismiss="modal"></span></div>
                    <div class="wrap">
                        <div class="tf-mini-cart-threshold js-mini-threshold d-none">
                            <div class="tf-progress-bar"><div class="value js-mini-progress" style="width:0%"><i class="icon icon-shipping"></i></div></div>
                            <div class="text-caption-1 js-mini-threshold-text"></div>
                        </div>
                        <div class="tf-mini-cart-wrap">
                            <div class="tf-mini-cart-main">
                                <div class="tf-mini-cart-sroll">
                                    <div class="tf-mini-cart-items js-mini-items"><p class="p-4 text-secondary">Loading…</p></div>
                                </div>
                            </div>
                            <div class="tf-mini-cart-bottom">
                                <div class="tf-mini-cart-bottom-wrap">
                                    <div class="tf-cart-totals-discounts js-mini-discount-row d-none"><span class="text-button">Discount</span><span class="text-button js-mini-discount"></span></div>
                                    <div class="tf-cart-totals-discounts"><h5>Subtotal</h5><h5 class="tf-totals-total-value js-mini-total">—</h5></div>
                                    <p class="text-caption-1 text-secondary mb_12">Delivery is worked out at checkout.</p>
                                    <div class="tf-mini-cart-view-checkout">
                                        <a href="{{ $base }}/cart" class="tf-btn w-100 btn-white radius-4 has-border"><span class="text">View basket</span></a>
                                        <a href="{{ $base }}/checkout" class="tf-btn w-100 btn-fill radius-4 js-mini-checkout"><span class="text">Check out</span></a>
                                    </div>
                                    <div class="text-center"><a class="link text-btn-uppercase" href="{{ $base }}/shop">Or continue shopping</a></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="store-toast" id="storeToast" role="status" aria-live="polite"></div>
    <div class="tf-product-info-by-btn d-none" aria-hidden="true"></div>{{-- the template's sticky-bar script expects one on every page --}}

    <script src="{{ $assets }}/js/bootstrap.min.js"></script>
    <script src="{{ $assets }}/js/jquery.min.js"></script>
    <script src="{{ $assets }}/js/swiper-bundle.min.js"></script>
    <script src="{{ $assets }}/js/carousel.js"></script>
    <script src="{{ $assets }}/js/lazysize.min.js"></script>
    <script src="{{ $assets }}/js/wow.min.js"></script>
    <script src="{{ $assets }}/js/multiple-modal.js"></script>
    <script src="{{ $assets }}/js/main.js"></script>
    <script>window.STORE = { base: @json($base), open: @json($shopOpen), csrf: @json(csrf_token()) };</script>
    <script src="{{ $assets }}/js/store.js"></script>
    @stack('scripts')
</body>
</html>
