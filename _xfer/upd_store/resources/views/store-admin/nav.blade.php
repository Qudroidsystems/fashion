@php $u = auth()->user(); @endphp
<ul class="nav nav-pills gap-1 mb-3 flex-nowrap overflow-auto pb-1" style="scrollbar-width:thin">
    @can('View online orders')
        <li class="nav-item"><a href="{{ route('store.dashboard') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.dashboard') ? 'active' : '' }}"><i class="ri-store-3-line me-1"></i>Overview</a></li>
        <li class="nav-item"><a href="{{ route('store.orders.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.orders.*') ? 'active' : '' }}"><i class="ri-shopping-bag-3-line me-1"></i>Orders</a></li>
    @endcan
    @can('View product')<li class="nav-item"><a href="{{ route('store.products.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.products.*') ? 'active' : '' }}"><i class="ri-image-2-line me-1"></i>Products & pictures</a></li>@endcan
    @can('Manage online store')
        <li class="nav-item"><a href="{{ route('store.coupons.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.coupons.*') ? 'active' : '' }}"><i class="ri-coupon-3-line me-1"></i>Coupons</a></li>
        <li class="nav-item"><a href="{{ route('store.shipping.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.shipping.*') ? 'active' : '' }}"><i class="ri-truck-line me-1"></i>Delivery</a></li>
        <li class="nav-item"><a href="{{ route('store.banners.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.banners.*') ? 'active' : '' }}"><i class="ri-slideshow-3-line me-1"></i>Banners</a></li>
    @endcan
    @can('Moderate reviews')<li class="nav-item"><a href="{{ route('store.reviews.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.reviews.*') ? 'active' : '' }}"><i class="ri-star-line me-1"></i>Reviews</a></li>@endcan
    @can('View online orders')<li class="nav-item"><a href="{{ route('store.accounts.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.accounts.*') ? 'active' : '' }}"><i class="ri-user-heart-line me-1"></i>Shoppers</a></li>@endcan
    @can('Manage online store')<li class="nav-item"><a href="{{ route('store.settings.edit') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('store.settings.*') ? 'active' : '' }}"><i class="ri-settings-3-line me-1"></i>Settings</a></li>@endcan
</ul>
