@extends('sites.layout')
@section('title', 'Wishlist')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Your wishlist'])
    <section class="flat-spacing">
        <div class="container">
            @if($products->isEmpty())
                <div class="store-empty"><i class="icon icon-heart"></i><h5>Nothing saved yet</h5><p class="text-secondary">Tap the heart on any product to keep it here.</p>
                    <a href="{{ $base }}/shop" class="tf-btn btn-fill mt-3"><span class="text">Browse the shop</span></a></div>
            @else
                @unless($account)<p class="text-secondary mb-4"><a href="{{ $base }}/account/login?next=wishlist" class="link">Sign in</a> to keep your wishlist on every device.</p>@endunless
                <div class="tf-grid-layout tf-col-2 md-col-3 xl-col-4">
                    @foreach($products as $p)@include('sites.partials.product-card', ['p' => $p])@endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
