@extends('sites.layout')
@section('title', 'Order ' . $order->number)

@section('body')
    @php
        use App\Support\Money;
        $o = $order; $cur = $o->currency;
        $steps = $o->fulfilment === 'pickup'
            ? ['awaiting_confirmation' => 'Received', 'confirmed' => 'Confirmed', 'processing' => 'Preparing', 'ready_for_pickup' => 'Ready for pickup', 'delivered' => 'Collected']
            : ['awaiting_confirmation' => 'Received', 'confirmed' => 'Confirmed', 'processing' => 'Preparing', 'shipped' => 'On its way', 'delivered' => 'Delivered'];
        $keys = array_keys($steps);
        $at = $o->status === 'pending_payment' ? -1 : array_search($o->status, $keys, true);
        $owing = $o->canPayOnline();
        $tz = $house->timezone ?: config('app.timezone');
    @endphp

    <section class="flat-spacing">
        <div class="container" style="max-width:980px">
            <div class="store-order-head">
                @if(session('placed'))
                    <div class="store-check"><i class="icon icon-checkCircle"></i></div>
                    <h3>Thank you, {{ $o->first_name }}!</h3>
                    <p class="text-secondary">@if($o->payment_status === 'paid')Your payment is received and your order is confirmed.@elseif($o->payment_method === 'whatsapp')Your order is saved. Send it to us on WhatsApp so we can confirm it with you.@else Your order is in. We have sent the details{{ $o->email ? ' to ' . $o->email : '' }}.@endif</p>
                @else
                    <h3>Order {{ $o->number }}</h3>
                @endif
                <p class="mb-0"><span class="store-pill">{{ $o->number }}</span> <span class="store-pill {{ $o->status === 'cancelled' ? 'is-bad' : 'is-good' }}">{{ $o->customer_status_label }}</span>
                    <span class="store-pill">{{ $o->payment_status_label }}</span>
                    <span class="text-caption-1 text-secondary ms-1">Placed {{ $o->created_at->setTimezone($tz)->format('j M Y, H:i') }}</span></p>
            </div>

            @if($o->status !== 'cancelled')
            <ol class="store-steps-bar">
                @foreach($steps as $k => $label)<li class="{{ $at !== false && $loop->index <= $at ? 'done' : '' }}">{{ $label }}</li>@endforeach
            </ol>
            @else
                <div class="store-alert">This order was cancelled{{ $o->cancel_reason ? ': ' . $o->cancel_reason : '.' }}</div>
            @endif

            <div class="row g-4 mt-1">
                <div class="col-lg-7">
                    @if($owing)
                    <div class="store-card">
                        <h5 class="mb-1">Pay {{ Money::format($o->balance(), $cur) }}</h5>
                        <p class="text-secondary text-caption-1">@if($o->payment_status === 'pending')We have your transfer receipt and are checking it. You do not need to do anything else.@else Choose how you would like to pay. Your items are reserved{{ $o->expires_at ? ' until ' . $o->expires_at->setTimezone($tz)->format('j M, H:i') : '' }}.@endif</p>
                        @if(request('cancelled'))<p class="store-err">Payment was not completed. You can try again.</p>@endif

                        @if($cardMethods)
                            <div class="d-flex gap-2 flex-wrap mb-3">
                                @foreach($cardMethods as $k => $label)
                                    <form method="POST" action="{{ $base }}/order/{{ $o->token }}/pay">@csrf<input type="hidden" name="method" value="{{ $k }}">
                                        <button class="tf-btn btn-fill"><span class="text">{{ $k === 'stripe' ? 'Pay by card (Stripe)' : 'Pay now (Paystack)' }}</span></button></form>
                                @endforeach
                            </div>
                        @endif

                        @if(! empty($bank['account_number']))
                            <div class="store-bank">
                                <p class="text-btn-uppercase mb-2">Bank transfer</p>
                                <dl>
                                    <dt>Bank</dt><dd>{{ $bank['bank_name'] }}</dd>
                                    <dt>Account number</dt><dd><strong class="js-copy" data-copy="{{ $bank['account_number'] }}">{{ $bank['account_number'] }}</strong></dd>
                                    <dt>Account name</dt><dd>{{ $bank['account_name'] ?: $house->name }}</dd>
                                    <dt>Amount</dt><dd><strong>{{ Money::format($o->balance(), $cur) }}</strong></dd>
                                    <dt>Reference</dt><dd>{{ $o->number }}</dd>
                                </dl>
                                @if(! empty($bank['instructions']))<p class="text-caption-1 text-secondary">{{ $bank['instructions'] }}</p>@endif
                                <form method="POST" action="{{ $base }}/order/{{ $o->token }}/proof" enctype="multipart/form-data" class="store-form mt-3">
                                    @csrf
                                    <label class="text-caption-1 fw-6">Paid? Send us your receipt</label>
                                    <input type="file" name="proof" accept="image/*,application/pdf" aria-label="Transfer receipt">
                                    <input type="text" name="reference" maxlength="120" placeholder="Transfer reference or sender name (optional)" value="{{ old('reference') }}">
                                    @error('proof')<div class="store-err">{{ $message }}</div>@enderror
                                    <button class="tf-btn btn-white has-border"><span class="text">Send receipt</span></button>
                                    @if($o->proof_uploaded_at)<p class="text-caption-1 text-success mt-2">Receipt received {{ $o->proof_uploaded_at->setTimezone($tz)->format('j M, H:i') }} — thank you.</p>@endif
                                </form>
                            </div>
                        @endif
                    </div>
                    @endif

                    @if($waOrder && $o->status !== 'cancelled')
                        <a href="{{ $waOrder }}" target="_blank" rel="noopener" class="store-wa-btn mt-3 js-wa-order" @if(session('open_whatsapp')) data-autoopen="1" @endif>
                            <i class="icon icon-whatsapp"></i> {{ $o->payment_method === 'whatsapp' && $o->isAwaiting() ? 'Send this order on WhatsApp' : 'Chat about this order on WhatsApp' }}
                        </a>
                    @endif

                    <div class="store-card mt-3">
                        <h5>Items</h5>
                        @foreach($o->items as $i)
                            <div class="store-line">
                                <div class="pic">@include('sites.partials-pic', ['src' => $i->image_url, 'alt' => $i->name])</div>
                                <div class="flex-grow-1"><div class="fw-6">{{ $i->name }}</div>@if($i->variant_label)<div class="text-caption-1 text-secondary">{{ $i->variant_label }}</div>@endif
                                    <div class="text-caption-1">{{ $i->quantity }} × {{ Money::format($i->unit_price, $cur) }}</div></div>
                                <div class="fw-6">{{ Money::format($i->line_total, $cur) }}</div>
                            </div>
                        @endforeach
                        <dl class="store-totals">
                            <dt>Subtotal</dt><dd>{{ Money::format($o->subtotal, $cur) }}</dd>
                            @if($o->discount_total > 0)<dt>Discount{{ $o->coupon_code ? ' (' . $o->coupon_code . ')' : '' }}</dt><dd>-{{ Money::format($o->discount_total, $cur) }}</dd>@endif
                            <dt>{{ $o->fulfilment === 'pickup' ? 'Pickup' : 'Delivery' }}</dt><dd>{{ $o->shipping_total > 0 ? Money::format($o->shipping_total, $cur) : 'Free' }}</dd>
                            <dt class="total">Total</dt><dd class="total">{{ Money::format($o->total, $cur) }}</dd>
                            @if($o->amount_paid > 0)<dt>Paid</dt><dd>{{ Money::format($o->amount_paid, $cur) }}</dd><dt>Still to pay</dt><dd>{{ Money::format($o->balance(), $cur) }}</dd>@endif
                        </dl>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="store-card">
                        <h6>{{ $o->fulfilment === 'pickup' ? 'Collect from' : 'Deliver to' }}</h6>
                        @if($o->fulfilment === 'pickup')
                            <p class="mb-0">{{ $o->pickupBranch?->name }}<br><span class="text-secondary">{{ collect([$o->pickupBranch?->address, $o->pickupBranch?->city])->filter()->implode(', ') }}</span></p>
                        @else
                            <p class="mb-0">{{ $o->name }}<br><span class="text-secondary">{{ $o->address }}</span></p>
                            @if($o->tracking_number)<p class="text-caption-1 mt-2">{{ $o->courier }} tracking: <strong>{{ $o->tracking_number }}</strong></p>@endif
                        @endif
                        <p class="text-caption-1 text-secondary mt-2 mb-0">{{ $o->phone }}{{ $o->email ? ' · ' . $o->email : '' }}</p>
                        <hr>
                        <h6>Payment</h6>
                        <p class="mb-0">{{ $o->payment_method_label }} — {{ $o->payment_status_label }}</p>
                    </div>
                    @if($o->events->isNotEmpty())
                    <div class="store-card mt-3">
                        <h6>Updates</h6>
                        <ul class="store-timeline">
                            @foreach($o->events->sortByDesc('id') as $e)
                                <li><span class="text-caption-1 text-secondary">{{ $e->created_at->setTimezone($tz)->format('j M, H:i') }}</span><br>{{ $e->status ? (\App\Models\ShopOrder::STATUSES[$e->status][2] ?? \App\Models\ShopOrder::PAYMENT_STATUSES[$e->status][0] ?? '') : '' }}@if($e->note){{ $e->status ? ' — ' : '' }}{{ $e->note }}@endif</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                    @if($o->isAwaiting() && $o->amount_paid <= 0)
                        <form method="POST" action="{{ $base }}/order/{{ $o->token }}/cancel" class="mt-3" onsubmit="return confirm('Cancel this order?')">@csrf
                            <button class="btn btn-link text-secondary p-0 text-caption-1">Cancel this order</button></form>
                    @endif
                    <p class="text-caption-1 text-secondary mt-3">Keep this page’s link — it is your private link to this order. <a href="{{ $base }}/shop" class="link">Continue shopping</a></p>
                </div>
            </div>
        </div>
    </section>
@endsection
