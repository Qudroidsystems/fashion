@extends('sites.layout')
@section('title', 'Checkout')

@section('body')
    @php
        use App\Support\Money;
        $s = $summary; $cur = $s['currency'];
        $ful = old('fulfilment', $delivers ? 'delivery' : 'pickup');
        $method = old('payment_method', array_key_first($methods));
        $hints = [
            'paystack' => 'You will be taken to Paystack to pay securely by card, bank transfer or USSD.',
            'stripe' => 'You will be taken to Stripe to pay securely by card.',
            'bank_transfer' => 'Transfer to our account after placing the order, then upload your receipt on the order page. We reserve your items while we wait.',
            'whatsapp' => 'We save your order and open WhatsApp with the full order details, so you can agree payment and delivery with us directly.',
            'pay_on_delivery' => 'Pay when your order arrives or when you collect it.',
        ];
    @endphp
    @include('sites.partials.page-title', ['title' => 'Checkout', 'crumbs' => ['Basket' => $base . '/cart']])

    <section>
        <div class="container">
            <form method="POST" action="{{ $base }}/checkout" class="row js-checkout" data-quote="{{ $base }}/checkout/quote" novalidate>
                @csrf
                <div class="col-xl-6">
                    <div class="flat-spacing tf-page-checkout">
                        @if($errors->any())
                            <div class="store-alert mb-4" role="alert">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
                        @endif
                        @unless($account)
                        <div class="wrap">
                            <div class="title-login"><p>Already have an account?</p><a href="{{ $base }}/account/login?next=checkout" class="text-button">Sign in</a></div>
                        </div>
                        @endunless

                        <div class="wrap">
                            <h5 class="title">Your details</h5>
                            <div class="info-box">
                                <div class="grid-2">
                                    <input type="text" name="first_name" placeholder="First name *" required maxlength="80" value="{{ old('first_name', $account?->first_name) }}" autocomplete="given-name" aria-label="First name">
                                    <input type="text" name="last_name" placeholder="Last name" maxlength="80" value="{{ old('last_name', $account?->last_name) }}" autocomplete="family-name" aria-label="Last name">
                                </div>
                                <div class="grid-2">
                                    <input type="email" name="email" placeholder="E-mail *" maxlength="160" value="{{ old('email', $account?->email) }}" autocomplete="email" aria-label="E-mail" class="js-q">
                                    <input type="tel" name="phone" placeholder="Phone / WhatsApp *" required maxlength="30" value="{{ old('phone', $account?->phone ?? $address?->phone) }}" autocomplete="tel" aria-label="Phone">
                                </div>
                            </div>
                        </div>

                        <div class="wrap">
                            <h5 class="title">How would you like to receive it?</h5>
                            <div class="store-choice">
                                @if($delivers)
                                <label class="store-choice-item"><input type="radio" name="fulfilment" value="delivery" class="tf-check-rounded js-q js-ful" @checked($ful === 'delivery')><span><strong>Delivery</strong><small>To your address</small></span></label>
                                @endif
                                @if($pickups->isNotEmpty())
                                <label class="store-choice-item"><input type="radio" name="fulfilment" value="pickup" class="tf-check-rounded js-q js-ful" @checked($ful === 'pickup')><span><strong>Pickup</strong><small>{{ $shop['pickup']['note'] ?? 'Collect from us' }}</small></span></label>
                                @endif
                            </div>

                            <div class="info-box mt-3 js-delivery {{ $ful === 'delivery' ? '' : 'd-none' }}">
                                <input type="text" name="address_line1" placeholder="Street address *" maxlength="190" value="{{ old('address_line1', $address?->line1) }}" autocomplete="address-line1" aria-label="Street address">
                                <input type="text" name="address_line2" placeholder="Apartment, landmark (optional)" maxlength="190" value="{{ old('address_line2', $address?->line2) }}" autocomplete="address-line2" aria-label="Address line 2">
                                <div class="grid-2">
                                    <input type="text" name="city" placeholder="Town / city *" maxlength="80" value="{{ old('city', $address?->city) }}" autocomplete="address-level2" aria-label="Town or city">
                                    @if($states)
                                        <div class="tf-select"><select name="state" class="js-q" aria-label="State"><option value="">State *</option>@foreach($states as $st)<option @selected(old('state', $address?->state) === $st)>{{ $st }}</option>@endforeach</select></div>
                                    @else
                                        <input type="text" name="state" placeholder="State / region" maxlength="80" value="{{ old('state', $address?->state) }}" class="js-q" aria-label="State or region">
                                    @endif
                                </div>
                                <div class="grid-2">
                                    <input type="text" name="country" placeholder="Country" maxlength="80" value="{{ old('country', $address?->country ?? (($house->currency_code ?: 'NGN') === 'NGN' ? 'Nigeria' : '')) }}" aria-label="Country">
                                    <input type="text" name="postal_code" placeholder="Postal code" maxlength="20" value="{{ old('postal_code', $address?->postal_code) }}" aria-label="Postal code">
                                </div>
                                <p class="text-caption-1 text-secondary js-eta"></p>
                                @if($account)<label class="d-flex gap-2 align-items-center text-caption-1"><input type="checkbox" name="save_address" value="1" class="tf-check" @checked(! $address)> Save this address to my account</label>@endif
                            </div>

                            @if($pickups->isNotEmpty())
                            <div class="mt-3 js-pickup {{ $ful === 'pickup' ? '' : 'd-none' }}">
                                @foreach($pickups as $b)
                                    <label class="store-choice-item w-100 mb-2"><input type="radio" name="pickup_branch_id" value="{{ $b->id }}" class="tf-check-rounded" @checked((int) old('pickup_branch_id', $pickups->first()->id) === $b->id)>
                                        <span><strong>{{ $b->name }}</strong><small>{{ collect([$b->address, $b->city])->filter()->implode(', ') }}</small></span></label>
                                @endforeach
                            </div>
                            @endif
                        </div>

                        <div class="wrap">
                            <h5 class="title">Payment</h5>
                            @if(! $methods)
                                <div class="store-alert">The shop cannot take orders right now. Please <a href="{{ $base }}/contact" class="link">contact us</a>.</div>
                            @else
                            <div class="payment-box store-payment-box">
                                @foreach($methods as $k => $label)
                                    <label class="payment-item store-pay {{ $method === $k ? 'active' : '' }}">
                                        <span class="payment-header"><input type="radio" name="payment_method" value="{{ $k }}" class="tf-check-rounded js-method" @checked($method === $k)>
                                            <span class="text-title">@if($k === 'whatsapp')<i class="icon icon-whatsapp text-success"></i> @endif{{ $label }}</span></span>
                                        <span class="payment-body d-block text-secondary text-caption-1">{{ $hints[$k] }}
                                            @if($k === 'bank_transfer' && ! empty($shop['bank']['account_number']))<br><strong>{{ $shop['bank']['bank_name'] }} · {{ $shop['bank']['account_number'] }} · {{ $shop['bank']['account_name'] }}</strong>@endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @endif
                            <textarea name="customer_note" placeholder="A note for us (optional) — e.g. delivery instructions" maxlength="1000" class="mt-3" aria-label="Note">{{ old('customer_note') }}</textarea>
                            <label class="d-flex gap-2 align-items-start text-caption-1 mt-3"><input type="checkbox" name="agree" value="1" class="tf-check" @checked(old('agree'))>
                                <span>I agree to the @if(! empty($shop['policies']['terms']))<a href="{{ $base }}/pages/terms" target="_blank" class="link">terms</a>@else terms @endif @if(! empty($shop['policies']['returns'])) and <a href="{{ $base }}/pages/returns" target="_blank" class="link">returns policy</a>@endif.</span></label>
                            <button class="tf-btn btn-reset w-100 mt-3 js-place" {{ $methods ? '' : 'disabled' }}><span class="js-place-label">{{ $method === 'whatsapp' ? 'Send order on WhatsApp' : (in_array($method, ['paystack', 'stripe'], true) ? 'Continue to payment' : 'Place order') }}</span></button>
                        </div>
                    </div>
                </div>
                <div class="col-xl-1"><div class="line-separation"></div></div>
                <div class="col-xl-5">
                    <div class="flat-spacing flat-sidebar-checkout">
                        <div class="sidebar-checkout-content">
                            <h5 class="title">Your order</h5>
                            <div class="list-product">
                                @foreach($s['lines'] as $l)
                                    <div class="item-product">
                                        <a href="{{ $base }}/product/{{ $l['slug'] }}" class="img-product">@include('sites.partials-pic', ['src' => $l['image'], 'alt' => $l['name']])</a>
                                        <div class="content-box">
                                            <div class="info">
                                                <a href="{{ $base }}/product/{{ $l['slug'] }}" class="name-product link text-title">{{ $l['name'] }}</a>
                                                @if($l['label'])<div class="variant text-caption-1 text-secondary">{{ $l['label'] }}</div>@endif
                                            </div>
                                            <div class="total-price text-button"><span class="count">{{ $l['qty'] }}</span>×<span class="price">{{ Money::format($l['unit'], $cur) }}</span></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @if($s['coupon_code'])<p class="text-caption-1">Code <strong>{{ $s['coupon_code'] }}</strong> applied — <a href="{{ $base }}/cart" class="link">change</a></p>
                            @else<p class="text-caption-1"><a href="{{ $base }}/cart" class="link">Have a discount code? Add it in your basket.</a></p>@endif
                            <div class="sec-total-price">
                                <div class="top">
                                    <div class="item d-flex align-items-center justify-content-between text-button"><span>Subtotal</span><span class="js-t-sub">{{ Money::format($s['subtotal'], $cur) }}</span></div>
                                    <div class="item d-flex align-items-center justify-content-between text-button js-t-disc-row {{ $s['discount'] > 0 ? '' : 'd-none' }}"><span>Discount</span><span class="js-t-disc">-{{ Money::format($s['discount'], $cur) }}</span></div>
                                    <div class="item d-flex align-items-center justify-content-between text-button"><span>Delivery <small class="text-secondary js-t-ship-label"></small></span><span class="js-t-ship">{{ $ful === 'pickup' ? 'Free (pickup)' : ($s['delivery'] ? ($s['shipping'] > 0 ? Money::format($s['shipping'], $cur) : 'Free') : 'Choose your state') }}</span></div>
                                </div>
                                <div class="bottom"><h5 class="d-flex justify-content-between"><span>Total</span><span class="total-price-checkout js-t-total">{{ Money::format($s['total'], $cur) }}</span></h5></div>
                            </div>
                            <p class="text-caption-1 text-secondary mt-3"><i class="icon icon-security"></i> Prices and stock are checked again when you place the order.</p>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>
@endsection
