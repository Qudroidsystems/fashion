@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php $m = $s['methods']; @endphp
    <x-cb.hero title="Shop settings" icon="ri-settings-3-line" subtitle="Open your shop, choose how customers pay and how they receive their orders.">
        <x-slot:pills><span class="status-pill {{ $open ? 'st-success' : 'st-muted' }}">{{ $open ? 'Open' : 'Closed' }}</span></x-slot:pills>
        <x-slot:actions><a href="{{ $url }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-external-link-line me-1"></i>View shop</a></x-slot:actions>
    </x-cb.hero>
    @include('store-admin.nav')
    @include('inventory.partials.flash')
    @unless($siteLive)
        <div class="cb-banner warning"><i class="ri-global-line"></i><div>Your website is switched off, so the shop can’t open yet. <a href="{{ route('website.edit') }}">Switch it on in Settings › Website</a>.</div></div>
    @endunless

    <form method="POST" action="{{ route('store.settings.update') }}">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-xl-6">
                <x-cb.card title="Shop" icon="ri-store-3-line">
                    <label class="d-flex gap-2 fw-semibold mb-2"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $s['enabled']))> Shop is open — customers can add to basket and check out</label>
                    <p class="small text-muted">Address: <a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a></p>
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Sell stock from</label><select name="fulfilment_branch_id" class="form-select">
                            <option value="">Main branch</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('fulfilment_branch_id', $s['fulfilment_branch_id']) == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Show “only N left” at</label><input type="number" min="0" max="100" name="low_stock_badge" class="form-control" value="{{ old('low_stock_badge', $s['low_stock_badge']) }}"></div>
                        <div class="col-md-6"><label class="form-label">Hold stock for card checkouts (hours)</label><input type="number" min="1" max="72" name="card_hold_hours" class="form-control" value="{{ old('card_hold_hours', $s['card_hold_hours']) }}" placeholder="{{ config('store.card_hold_hours') }}"></div>
                        <div class="col-md-6"><label class="form-label">Hold for transfer / WhatsApp (days)</label><input type="number" min="1" max="30" name="manual_hold_days" class="form-control" value="{{ old('manual_hold_days', $s['manual_hold_days']) }}" placeholder="{{ config('store.manual_hold_days') }}"></div>
                        <div class="col-12"><label class="d-flex gap-2"><input type="hidden" name="guest_checkout" value="0"><input type="checkbox" name="guest_checkout" value="1" @checked(old('guest_checkout', $s['guest_checkout']))> Allow checkout without an account</label></div>
                        <div class="col-12"><label class="d-flex gap-2"><input type="hidden" name="hide_out_of_stock" value="0"><input type="checkbox" name="hide_out_of_stock" value="1" @checked(old('hide_out_of_stock', $s['hide_out_of_stock']))> Hide sold-out products</label></div>
                        <div class="col-12"><label class="d-flex gap-2"><input type="hidden" name="allow_backorder" value="0"><input type="checkbox" name="allow_backorder" value="1" @checked(old('allow_backorder', $s['allow_backorder']))> Keep selling when stock runs out (made to order)</label></div>
                        <div class="col-12"><label class="form-label">Announcement bar</label><input name="announcement" class="form-control" maxlength="160" value="{{ old('announcement', $s['announcement']) }}" placeholder="Free delivery in Lagos on orders over ₦50,000"></div>
                        <div class="col-12"><label class="form-label">Also e-mail new orders to</label><input name="notify_emails" class="form-control" maxlength="500" value="{{ old('notify_emails', $s['notify_emails']) }}" placeholder="orders@yourhouse.com, manager@yourhouse.com">
                            <div class="small text-muted">Staff who can see online orders always get an in-app alert.</div></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Delivery & pickup" icon="ri-truck-line" class="mt-3">
                    <label class="d-flex gap-2 fw-semibold"><input type="hidden" name="delivery_enabled" value="0"><input type="checkbox" name="delivery_enabled" value="1" @checked(old('delivery_enabled', $s['delivery']['enabled']))> Deliver to customers</label>
                    <p class="small text-muted">Fees by state are set in <a href="{{ route('store.shipping.index') }}">Delivery zones</a>. Without zones this default applies (blank = agree the fee after the order).</p>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label">Default fee</label><input type="number" step="0.01" min="0" name="default_fee" class="form-control" value="{{ old('default_fee', $s['delivery']['default_fee']) }}"></div>
                        <div class="col-6"><label class="form-label">Usual delivery time</label><input name="default_eta" class="form-control" maxlength="60" value="{{ old('default_eta', $s['delivery']['default_eta']) }}" placeholder="2–5 working days"></div>
                    </div>
                    <label class="d-flex gap-2 fw-semibold"><input type="hidden" name="pickup_enabled" value="0"><input type="checkbox" name="pickup_enabled" value="1" @checked(old('pickup_enabled', $s['pickup']['enabled']))> Let customers collect from a branch</label>
                    <div class="d-flex flex-wrap gap-3 my-2">
                        @foreach($branches as $b)<label class="d-flex gap-2 small"><input type="checkbox" name="pickup_branch_ids[]" value="{{ $b->id }}" @checked(in_array($b->id, old('pickup_branch_ids', $s['pickup']['branch_ids'] ?? [])))> {{ $b->name }}</label>@endforeach
                    </div>
                    <div class="small text-muted mb-2">None ticked = every active branch.</div>
                    <input name="pickup_note" class="form-control" maxlength="160" value="{{ old('pickup_note', $s['pickup']['note']) }}" placeholder="Usually ready within 24 hours.">
                </x-cb.card>

                <x-cb.card title="Policies & size guide" icon="ri-file-text-line" class="mt-3">
                    <p class="small text-muted">Shown as pages in your shop’s footer and on product pages. Leave empty to hide.</p>
                    @foreach(['policy_shipping' => ['Delivery information', 'shipping'], 'policy_returns' => ['Returns & exchanges', 'returns'], 'policy_terms' => ['Terms & conditions', 'terms'], 'policy_privacy' => ['Privacy policy', 'privacy']] as $f => [$l, $k])
                        <label class="form-label mt-2">{{ $l }}</label><textarea name="{{ $f }}" class="form-control" rows="3">{{ old($f, $s['policies'][$k]) }}</textarea>
                    @endforeach
                    <label class="form-label mt-2">Size guide</label><textarea name="size_guide" class="form-control" rows="3" placeholder="S: bust 34–35&quot;, waist 27–28&quot;…">{{ old('size_guide', $s['size_guide']) }}</textarea>
                </x-cb.card>
            </div>

            <div class="col-xl-6">
                <x-cb.card title="How customers can pay" icon="ri-secure-payment-line">
                    @php $ready = fn ($k) => isset($available[$k]); @endphp
                    @foreach([
                        'paystack' => ['Paystack — card, bank, USSD', $paystackOn ? 'Using the keys in House settings.' : 'Add your Paystack keys in House settings › Online payments first.'],
                        'stripe' => ['Stripe — international cards', 'Add your Stripe keys below.'],
                        'bank_transfer' => ['Bank transfer', 'Customers transfer and upload their receipt; you confirm it.'],
                        'whatsapp' => ['Order on WhatsApp', 'Customers send the order to your WhatsApp and agree payment with your rep.'],
                        'pay_on_delivery' => ['Pay on delivery / pickup', 'You collect the money when you hand over.'],
                    ] as $k => [$label, $hint])
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <label class="d-flex gap-2"><input type="hidden" name="methods[{{ $k }}]" value="0"><input type="checkbox" name="methods[{{ $k }}]" value="1" @checked(old("methods.$k", $m[$k] ?? false))>
                                <span><span class="fw-semibold">{{ $label }}</span><br><span class="small text-muted">{{ $hint }}</span></span></label>
                            <span class="status-pill {{ $ready($k) ? 'st-success' : 'st-muted' }}">{{ $ready($k) ? 'Live' : 'Off' }}</span>
                        </div>
                    @endforeach
                    @if($paystackOn)<p class="small text-muted mt-2 mb-0">Paystack webhook (already used for payment links): <code>{{ $paystackHook }}</code></p>
                    @else<p class="small mt-2 mb-0"><a href="{{ route('settings.tenant.edit') }}#paystack">Set up Paystack in House settings</a></p>@endif
                </x-cb.card>

                <x-cb.card title="Bank transfer details" icon="ri-bank-line" class="mt-3">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Bank</label><input name="bank_name" class="form-control" maxlength="80" value="{{ old('bank_name', $s['bank']['bank_name']) }}"></div>
                        <div class="col-md-6"><label class="form-label">Account number</label><input name="account_number" class="form-control font-monospace" maxlength="40" value="{{ old('account_number', $s['bank']['account_number']) }}"></div>
                        <div class="col-12"><label class="form-label">Account name</label><input name="account_name" class="form-control" maxlength="120" value="{{ old('account_name', $s['bank']['account_name']) }}"></div>
                        <div class="col-12"><label class="form-label">Instructions</label><input name="bank_instructions" class="form-control" maxlength="500" value="{{ old('bank_instructions', $s['bank']['instructions']) }}" placeholder="Use your order number as the narration."></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="WhatsApp ordering" icon="ri-whatsapp-line" class="mt-3">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Reps’ WhatsApp number</label><input name="whatsapp_number" class="form-control" maxlength="30" value="{{ old('whatsapp_number', $s['whatsapp']['number']) }}" placeholder="{{ $waNumber ?: '0803 000 0000' }}">
                            <div class="small text-muted">Blank = the WhatsApp number on your website{{ $waNumber ? ' (' . $waNumber . ')' : '' }}.</div></div>
                        <div class="col-md-6"><label class="form-label">Button text</label><input name="whatsapp_button_label" class="form-control" maxlength="40" value="{{ old('whatsapp_button_label', $s['whatsapp']['button_label']) }}"></div>
                    </div>
                    <details class="mt-3" {{ $s['whatsapp']['cloud_enabled'] ? 'open' : '' }}>
                        <summary class="fw-semibold">Also send every new order to the reps automatically (WhatsApp Cloud API)</summary>
                        <p class="small text-muted mt-2">Needs a WhatsApp Business account on Meta. Messages to a rep who hasn’t written to your business number in the last 24 hours need an approved template with 4 body variables: order number, customer, total, link.</p>
                        <div class="row g-2">
                            <div class="col-12"><label class="d-flex gap-2"><input type="hidden" name="cloud_enabled" value="0"><input type="checkbox" name="cloud_enabled" value="1" @checked(old('cloud_enabled', $s['whatsapp']['cloud_enabled']))> Switched on</label></div>
                            <div class="col-md-6"><label class="form-label">Phone number ID</label><input name="cloud_phone_id" class="form-control font-monospace" maxlength="40" value="{{ old('cloud_phone_id', $s['whatsapp']['cloud_phone_id']) }}"></div>
                            <div class="col-md-6"><label class="form-label">Access token</label><input type="password" name="cloud_token" class="form-control font-monospace" autocomplete="new-password" placeholder="{{ ! empty($s['whatsapp']['cloud_token']) ? '•••••• saved — type to replace' : 'EAAG…' }}"></div>
                            <div class="col-12"><label class="form-label">Reps’ numbers to alert</label><input name="notify_numbers" class="form-control" maxlength="300" value="{{ old('notify_numbers', $s['whatsapp']['notify_numbers']) }}" placeholder="0803…, 0805…"></div>
                            <div class="col-md-8"><label class="form-label">Template name</label><input name="template" class="form-control" maxlength="80" value="{{ old('template', $s['whatsapp']['template']) }}" placeholder="new_order_alert"></div>
                            <div class="col-md-4"><label class="form-label">Language</label><input name="template_lang" class="form-control" maxlength="10" value="{{ old('template_lang', $s['whatsapp']['template_lang']) }}"></div>
                        </div>
                    </details>
                </x-cb.card>

                <x-cb.card title="Stripe" icon="ri-bank-card-line" class="mt-3">
                    <p class="small text-muted">From your Stripe dashboard › Developers › API keys. Add a webhook endpoint for <code>checkout.session.completed</code> and <code>checkout.session.async_payment_succeeded</code>:</p>
                    <div class="input-group mb-2"><input class="form-control font-monospace small" readonly value="{{ $stripeHook }}"><button type="button" class="btn btn-light" onclick="navigator.clipboard.writeText('{{ $stripeHook }}')"><i class="ri-file-copy-line"></i></button></div>
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label">Publishable key</label><input name="stripe_publishable_key" class="form-control font-monospace" maxlength="200" value="{{ old('stripe_publishable_key', $s['stripe']['publishable_key']) }}" placeholder="pk_live_…"></div>
                        <div class="col-md-6"><label class="form-label">Secret key</label><input type="password" name="stripe_secret_key" class="form-control font-monospace" autocomplete="new-password" placeholder="{{ ! empty($s['stripe']['secret_key']) ? '•••••• saved' : 'sk_live_…' }}"></div>
                        <div class="col-md-6"><label class="form-label">Webhook signing secret</label><input type="password" name="stripe_webhook_secret" class="form-control font-monospace" autocomplete="new-password" placeholder="{{ ! empty($s['stripe']['webhook_secret']) ? '•••••• saved' : 'whsec_…' }}"></div>
                        @if(! empty($s['stripe']['secret_key']))<div class="col-12"><label class="d-flex gap-2 small text-danger"><input type="checkbox" name="stripe_forget" value="1"> Remove the saved Stripe keys</label></div>@endif
                    </div>
                </x-cb.card>
            </div>
        </div>
        <div class="mt-3 text-end"><button class="action-btn btn-primary-cb px-4 py-2"><i class="ri-save-line"></i>Save settings</button></div>
    </form>
</div></div></div>
@endsection
