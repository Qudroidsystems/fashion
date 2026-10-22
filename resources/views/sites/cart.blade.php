@extends('sites.layout')
@section('title', 'Your basket')

@section('body')
    @php use App\Support\Money; $s = $summary; $cur = $s['currency']; @endphp
    @include('sites.partials.page-title', ['title' => 'Your basket', 'crumbs' => ['Shop' => $base . '/shop']])

    <section class="flat-spacing">
        <div class="container">
            @if(! $s['lines'])
                <div class="store-empty">
                    <i class="icon icon-ShoppingBagOpen"></i>
                    <h5>Your basket is empty</h5>
                    <p class="text-secondary">Have a look around — new pieces arrive often.</p>
                    <a href="{{ $base }}/shop" class="tf-btn btn-fill mt-3"><span class="text">Start shopping</span></a>
                </div>
            @else
            <div class="row">
                <div class="col-xl-8">
                    @if($s['free_threshold'])
                        @php $left = max(0, $s['free_threshold'] - ($s['subtotal'] - $s['discount'])); @endphp
                        <div class="tf-cart-sold mb-3">
                            <div class="notification-progress">
                                <div class="text">@if($left > 0)Spend <span class="fw-semibold text-primary">{{ Money::format($left, $cur) }}</span> more for <span class="fw-semibold">free delivery</span>@else You have <span class="fw-semibold">free delivery</span> on eligible addresses!@endif</div>
                                <div class="progress-cart"><div class="value" style="width: {{ min(100, (int) round(($s['subtotal'] - $s['discount']) / $s['free_threshold'] * 100)) }}%;"><span class="round"></span></div></div>
                            </div>
                        </div>
                    @endif
                    @if($s['problems'])<div class="store-alert">Some items have changed since you added them. Please adjust the quantities marked below.</div>@endif
                    <table class="tf-table-page-cart">
                        <thead><tr><th>Product</th><th>Price</th><th>Quantity</th><th>Total</th><th></th></tr></thead>
                        <tbody>
                        @foreach($s['lines'] as $l)
                            <tr class="tf-cart-item">
                                <td class="tf-cart-item_product">
                                    <a href="{{ $l['slug'] ? $base . '/product/' . $l['slug'] : '#' }}" class="img-box">@include('sites.partials-pic', ['src' => $l['image'], 'alt' => $l['name']])</a>
                                    <div class="cart-info">
                                        <a href="{{ $l['slug'] ? $base . '/product/' . $l['slug'] : '#' }}" class="cart-title link">{{ $l['name'] }}</a>
                                        @if($l['label'])<div class="text-caption-1 text-secondary">{{ $l['label'] }}</div>@endif
                                        @if($l['problem'])<div class="store-err">{{ $l['problem'] }}</div>@endif
                                    </div>
                                </td>
                                <td data-cart-title="Price" class="tf-cart-item_price text-center">
                                    <div class="cart-price text-button">@if($l['compare'])<span class="old-price text-secondary text-decoration-line-through me-1">{{ Money::format($l['compare'], $cur) }}</span>@endif{{ Money::format($l['unit'], $cur) }}</div>
                                </td>
                                <td data-cart-title="Quantity" class="tf-cart-item_quantity">
                                    <form method="POST" action="{{ $base }}/cart/update" class="wg-quantity mx-md-auto js-qty-form">
                                        @csrf<input type="hidden" name="item" value="{{ $l['id'] }}">
                                        <button type="submit" name="set" value="{{ max(0, $l['qty'] - 1) }}" class="btn-quantity" aria-label="Less">-</button>
                                        <input type="number" name="quantity" value="{{ $l['qty'] }}" min="0" max="{{ $l['max'] ?? 99 }}" aria-label="Quantity" onchange="this.form.submit()">
                                        <button type="submit" name="set" value="{{ $l['qty'] + 1 }}" class="btn-quantity" aria-label="More" @disabled($l['max'] !== null && $l['qty'] >= $l['max'])>+</button>
                                    </form>
                                </td>
                                <td data-cart-title="Total" class="tf-cart-item_total text-center"><div class="cart-total text-button">{{ Money::format($l['line_total'], $cur) }}</div></td>
                                <td data-cart-title="Remove" class="remove-cart">
                                    <form method="POST" action="{{ $base }}/cart/remove">@csrf<input type="hidden" name="item" value="{{ $l['id'] }}"><button class="btn p-0" aria-label="Remove {{ $l['name'] }}"><span class="icon icon-close"></span></button></form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <form method="POST" action="{{ $base }}/cart/coupon" class="ip-discount-code mt-4">
                        @csrf
                        <input type="text" name="code" value="{{ old('code', $s['coupon_code']) }}" placeholder="Discount code" aria-label="Discount code">
                        <button class="tf-btn"><span class="text">Apply code</span></button>
                    </form>
                    @error('code')<div class="store-err mt-2">{{ $message }}</div>@enderror
                    @if($s['coupon_code'])
                        <form method="POST" action="{{ $base }}/cart/coupon" class="mt-2">@csrf<input type="hidden" name="remove" value="1">
                            <span class="text-caption-1">Code <strong>{{ $s['coupon_code'] }}</strong> applied ({{ $s['coupon']->summary }}).</span>
                            <button class="btn btn-link p-0 ms-2 text-caption-1">Remove</button></form>
                    @elseif($s['coupon_error'])<div class="store-err mt-2">{{ $s['coupon_error'] }}</div>@endif
                </div>
                <div class="col-xl-4">
                    <div class="fl-sidebar-cart">
                        <div class="box-order bg-surface">
                            <h5 class="title">Order summary</h5>
                            <div class="subtotal text-button d-flex justify-content-between align-items-center"><span>Subtotal</span><span class="total">{{ Money::format($s['subtotal'], $cur) }}</span></div>
                            @if($s['discount'] > 0)<div class="discount text-button d-flex justify-content-between align-items-center"><span>Discount</span><span class="total">-{{ Money::format($s['discount'], $cur) }}</span></div>@endif
                            <div class="text-button d-flex justify-content-between align-items-center"><span>Delivery</span><span class="text-secondary">{{ $s['free_shipping'] ? 'Free' : 'At checkout' }}</span></div>
                            <h5 class="total-order d-flex justify-content-between align-items-center"><span>Total</span><span class="total">{{ Money::format($s['subtotal'] - $s['discount'], $cur) }}</span></h5>
                            <div class="box-progress-checkout">
                                @if($s['problems'])
                                    <button class="tf-btn btn-reset" disabled>Fix your basket to continue</button>
                                @else
                                    <a href="{{ $base }}/checkout" class="tf-btn btn-reset">Proceed to checkout</a>
                                @endif
                                <p class="text-button text-center"><a href="{{ $base }}/shop" class="link">Or continue shopping</a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </section>
@endsection
