@extends('sites.layout')
@section('title', 'My account')

@section('body')
    @include('sites.partials.page-title', ['title' => 'My account'])
    <section class="flat-spacing">
        <div class="container">
            <div class="my-account-wrap">
                <div class="wrap-sidebar-account">
                    <div class="sidebar-account">
                        <div class="account-avatar">
                            <div class="store-avatar">{{ mb_strtoupper(mb_substr($account->first_name, 0, 1) . mb_substr((string) $account->last_name, 0, 1)) }}</div>
                            <h6 class="mb_4">{{ $account->name }}</h6>
                            <div class="body-text-1">{{ $account->email }}</div>
                        </div>
                        <ul class="my-account-nav">
                            @foreach(['details' => ['Account details', '/account'], 'orders' => ['My orders', '/account/orders'], 'addresses' => ['My addresses', '/account/addresses']] as $k => [$l, $href])
                                <li>@if($tab === $k)<span class="my-account-nav-item active">{{ $l }}</span>@else<a href="{{ $base . $href }}" class="my-account-nav-item">{{ $l }}</a>@endif</li>
                            @endforeach
                            <li><a href="{{ $base }}/wishlist" class="my-account-nav-item">Wishlist</a></li>
                            <li><form method="POST" action="{{ $base }}/account/logout">@csrf<button class="my-account-nav-item btn btn-link p-0 text-start">Sign out</button></form></li>
                        </ul>
                    </div>
                </div>
                <div class="my-account-content">@yield('account')</div>
            </div>
        </div>
    </section>
@endsection
