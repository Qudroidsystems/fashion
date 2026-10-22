@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php
        use App\Support\Money; use App\Models\ShopOrder;
        $u = auth()->user();
        $canManage = $u->can('Manage online orders');
        $canPay = $u->can('Confirm online payment');
        $balance = $o->balance();
        $stepLabels = ['processing' => ['Start preparing', 'ri-inbox-archive-line'], 'ready_for_pickup' => ['Ready for pickup', 'ri-store-2-line'],
            'shipped' => ['Mark as sent', 'ri-truck-line'], 'delivered' => [$o->fulfilment === 'pickup' ? 'Mark as collected' : 'Mark as delivered', 'ri-checkbox-circle-line']];
        $next = collect($next)->filter(fn ($s) => ! ($s === 'ready_for_pickup' && $o->fulfilment !== 'pickup') && ! ($s === 'shipped' && $o->fulfilment === 'pickup'))->values();
    @endphp

    <x-cb.hero :title="'Order ' . $o->number" icon="ri-shopping-bag-3-line"
               :subtitle="$o->name . ' · ' . $o->phone . ($o->email ? ' · ' . $o->email : '')" :back="route('store.orders.index')" back-label="Online orders">
        <x-slot:pills>
            <span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span>
            <span class="status-pill {{ $o->payment_status_pill }}">{{ $o->payment_status_label }}</span>
            <span class="cb-meta-pill">{{ $o->payment_method_label }}</span>
            <span class="cb-meta-pill">{{ $o->source === 'web' ? 'Shop' : ucfirst(str_replace('_', ' ', $o->source)) }}</span>
            <span class="cb-meta-pill"><i class="ri-time-line me-1"></i>{{ $o->created_at->timezone($tz)->format('d M Y, H:i') }}</span>
            @if($o->order)<a href="{{ route('orders.show', $o->order) }}" class="cb-meta-pill text-white"><i class="ri-file-list-3-line me-1"></i>{{ $o->order->number }}</a>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($waCustomer)<a href="{{ $waCustomer }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-whatsapp-line me-1"></i>Send to customer</a>@endif
            <a href="{{ $orderUrl }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-external-link-line me-1"></i>Customer’s page</a>
            <button type="button" class="cb-hero-btn" onclick="navigator.clipboard.writeText('{{ $orderUrl }}').then(()=>this.innerHTML='<i class=\'ri-check-line me-1\'></i>Copied')"><i class="ri-links-line me-1"></i>Copy link</button>
        </x-slot:actions>
    </x-cb.hero>
    @include('store-admin.nav')
    @include('inventory.partials.flash')

    @if($o->status === 'cancelled')
        <div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Cancelled {{ $o->cancelled_at?->timezone($tz)->format('d M Y H:i') }}: {{ $o->cancel_reason }}@if($o->amount_paid > 0) · {{ Money::format($o->amount_paid) }} paid is the customer’s credit (refund it from Payments).@endif</div></div>
    @elseif($o->status === 'awaiting_confirmation')
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>
            @if($o->payment_method === 'whatsapp')WhatsApp order: agree payment and delivery with the customer, change the items below if needed, then confirm it.
            @elseif($o->payment_method === 'bank_transfer')Waiting for a bank transfer. When the money is in your account, record the payment — that also confirms the order.
            @else Waiting for you to confirm this order.@endif
            Stock is held until {{ $o->expires_at?->timezone($tz)->format('d M, H:i') ?? 'you decide' }}.</div></div>
    @elseif($o->status === 'pending_payment')
        <div class="cb-banner info"><i class="ri-bank-card-line"></i><div>The customer started a card payment. It confirms itself when the provider reports it paid; unpaid checkouts are released {{ $o->expires_at?->timezone($tz)->diffForHumans() }}.</div></div>
    @endif
    @if($o->payment_status === 'pending')
        <div class="cb-banner info"><i class="ri-bank-line"></i><div><strong>The customer says they paid.</strong>@if($o->payment_reference) Reference: <strong>{{ $o->payment_reference }}</strong>.@endif
            @if($o->proof_url) <a href="{{ $o->proof_url }}" target="_blank" rel="noopener">Open the receipt</a>.@endif Check your bank, then record the payment.</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Items" icon="ri-t-shirt-air-line" :count="$o->itemCount()">
                @if($o->isAwaiting() && $canManage)
                    <form method="POST" action="{{ route('store.orders.revise', $o->public_id) }}" id="reviseForm">
                        @csrf @method('PUT')
                        <div class="table-responsive"><table class="table align-middle mb-2">
                            <thead class="table-light"><tr><th>Item</th><th style="width:110px">Qty</th><th style="width:150px">Price</th><th class="text-end">Total</th></tr></thead>
                            <tbody id="reviseRows">
                            @foreach($o->items as $i => $it)
                                <tr><td><input type="hidden" name="items[{{ $i }}][variant_id]" value="{{ $it->product_variant_id }}">{{ $it->name }} <span class="text-muted small">{{ $it->variant_label }}</span><div class="small text-muted">{{ $it->sku }}</div></td>
                                    <td><input type="number" name="items[{{ $i }}][quantity]" value="{{ $it->quantity }}" min="0" max="999" class="form-control form-control-sm"></td>
                                    <td><input type="number" step="0.01" min="0" name="items[{{ $i }}][unit_price]" value="{{ $it->unit_price }}" class="form-control form-control-sm" @unless($u->can('Override price')) readonly @endunless></td>
                                    <td class="text-end">{{ Money::format($it->line_total) }}</td></tr>
                            @endforeach
                            </tbody>
                        </table></div>
                        <div class="d-flex flex-wrap gap-2 align-items-end">
                            <div style="min-width:260px" class="flex-grow-1"><label class="form-label small mb-1">Add an item</label>
                                <select class="form-select form-select-sm" id="addVariant"><option value="">Choose…</option>
                                    @foreach($variants as $v)<option value="{{ $v['id'] }}" data-label="{{ $v['name'] }} {{ $v['label'] !== 'Standard' ? '— ' . $v['label'] : '' }}" data-price="{{ $v['price'] }}">{{ $v['name'] }}{{ $v['label'] !== 'Standard' ? ' — ' . $v['label'] : '' }} · {{ Money::format($v['price']) }}{{ $v['stock'] !== null ? ' · ' . $v['stock'] . ' free' : '' }}</option>@endforeach
                                </select></div>
                            @if($o->fulfilment === 'delivery')<div><label class="form-label small mb-1">Delivery fee</label><input type="number" step="0.01" min="0" name="shipping_fee" value="{{ $o->shipping_total }}" class="form-control form-control-sm" style="width:120px"></div>@endif
                            <div><label class="form-label small mb-1">Discount</label><input type="number" step="0.01" min="0" name="discount" value="{{ $o->discount_total }}" class="form-control form-control-sm" style="width:120px"></div>
                            <button class="action-btn btn-primary-cb"><i class="ri-save-line"></i>Save changes</button>
                        </div>
                        <p class="small text-muted mt-2 mb-0">Set a quantity to 0 to remove an item. The customer’s page updates straight away — send it to them again on WhatsApp.</p>
                    </form>
                @else
                    @foreach($o->items as $it)
                        <div class="d-flex gap-3 align-items-center py-2 border-bottom">
                            @if($it->image_url)<img src="{{ $it->image_url }}" alt="" class="rounded-2" style="width:44px;height:54px;object-fit:cover">@endif
                            <div class="flex-grow-1"><div class="fw-semibold">{{ $it->name }}</div><div class="small text-muted">{{ $it->variant_label }} · {{ $it->sku }}</div></div>
                            <div class="text-end"><div>{{ $it->quantity }} × {{ Money::format($it->unit_price) }}</div><div class="fw-semibold">{{ Money::format($it->line_total) }}</div></div>
                        </div>
                    @endforeach
                @endif
                <dl class="row mb-0 mt-3 small">
                    <dt class="col-8 text-end fw-normal">Subtotal</dt><dd class="col-4 text-end mb-1">{{ Money::format($o->subtotal) }}</dd>
                    @if($o->discount_total > 0)<dt class="col-8 text-end fw-normal">Discount {{ $o->coupon_code ? '(' . $o->coupon_code . ')' : '' }}</dt><dd class="col-4 text-end mb-1">-{{ Money::format($o->discount_total) }}</dd>@endif
                    <dt class="col-8 text-end fw-normal">{{ $o->fulfilment === 'pickup' ? 'Pickup' : 'Delivery (' . ($o->shipping_label ?? '') . ')' }}</dt><dd class="col-4 text-end mb-1">{{ Money::format($o->shipping_total) }}</dd>
                    <dt class="col-8 text-end">Total</dt><dd class="col-4 text-end fw-bold mb-1">{{ Money::format($o->total) }}</dd>
                    <dt class="col-8 text-end fw-normal">Paid</dt><dd class="col-4 text-end mb-1">{{ Money::format($o->amount_paid) }}</dd>
                    <dt class="col-8 text-end">Still to pay</dt><dd class="col-4 text-end fw-bold mb-0 {{ $balance > 0 ? 'text-danger' : 'text-success' }}">{{ Money::format($balance) }}</dd>
                </dl>
                @if($o->customer_note)<div class="cb-banner info mt-3 mb-0"><i class="ri-chat-quote-line"></i><div><strong>Customer’s note:</strong> {{ $o->customer_note }}</div></div>@endif
            </x-cb.card>

            <x-cb.card title="History" icon="ri-history-line" class="mt-3">
                @foreach($o->events->sortByDesc('id') as $e)
                    <div class="d-flex gap-3 py-2 border-bottom">
                        <div class="small text-muted" style="min-width:120px">{{ $e->created_at->timezone($tz)->format('d M, H:i') }}</div>
                        <div class="flex-grow-1">
                            @if($e->status && isset(ShopOrder::STATUSES[$e->status]))<span class="status-pill {{ ShopOrder::STATUSES[$e->status][1] }}">{{ ShopOrder::STATUSES[$e->status][0] }}</span>
                            @elseif($e->type === 'payment')<span class="status-pill st-success">Payment</span>@elseif($e->type === 'note')<span class="status-pill st-muted">Note</span>@endif
                            {{ $e->note }}
                            <div class="small text-muted">{{ $e->user?->name ?? ($e->type === 'payment' && ! $e->user_id ? 'Payment provider / customer' : 'Customer') }}{{ $e->customer_visible ? '' : ' · staff only' }}</div>
                        </div>
                    </div>
                @endforeach
                @if($canManage)
                    <form method="POST" action="{{ route('store.orders.note', $o->public_id) }}" class="d-flex gap-2 mt-3 flex-wrap">@csrf
                        <input name="note" class="form-control" placeholder="Add a note…" required maxlength="500" style="flex:1 1 260px">
                        <label class="small d-flex gap-1 align-items-center"><input type="checkbox" name="customer_visible" value="1"> Show to customer</label>
                        <button class="action-btn"><i class="ri-add-line"></i>Add</button>
                    </form>
                @endif
            </x-cb.card>

            @if($movements->isNotEmpty())
            <x-cb.card title="Stock" icon="ri-archive-line" class="mt-3">
                @foreach($movements as $m)<div class="small py-1">{{ $m->type_label }}: {{ $m->item?->name }} {{ $m->quantity > 0 ? '+' : '' }}{{ (float) $m->quantity }} · {{ $m->created_at->timezone($tz)->format('d M H:i') }}</div>@endforeach
            </x-cb.card>
            @endif
        </div>

        <div class="col-xl-4">
            <x-cb.card title="What next" icon="ri-flashlight-line">
                @if($o->isAwaiting() && $canManage)
                    <form method="POST" action="{{ route('store.orders.accept', $o->public_id) }}" class="mb-3">@csrf
                        <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-check-double-line"></i>Confirm order{{ $balance > 0 ? ' (payment later)' : '' }}</button>
                        <p class="small text-muted mt-1 mb-0">Creates the customer and a confirmed order; the stock leaves the shelf.</p>
                    </form>
                @endif

                @if($canPay && $o->status !== 'cancelled' && $balance > 0.004)
                    <details class="mb-3" {{ $o->payment_status === 'pending' || $o->payment_method === 'bank_transfer' ? 'open' : '' }}>
                        <summary class="fw-semibold mb-2">Record a payment</summary>
                        <form method="POST" action="{{ route('store.orders.payment', $o->public_id) }}" class="row g-2">@csrf
                            <div class="col-6"><label class="form-label small mb-1">Amount</label><input type="number" step="0.01" min="0.01" name="amount" value="{{ $balance }}" class="form-control form-control-sm" required></div>
                            <div class="col-6"><label class="form-label small mb-1">How</label><select name="method" class="form-select form-select-sm">
                                @foreach(['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'card' => 'Card / POS', 'mobile_money' => 'Mobile money', 'other' => 'Other'] as $k => $l)<option value="{{ $k }}" @selected(($o->payment_method === 'pay_on_delivery' ? 'cash' : 'bank_transfer') === $k)>{{ $l }}</option>@endforeach</select></div>
                            <div class="col-6"><label class="form-label small mb-1">Reference</label><input name="reference" value="{{ $o->payment_reference }}" class="form-control form-control-sm" maxlength="120"></div>
                            <div class="col-6"><label class="form-label small mb-1">Received</label><input type="datetime-local" name="received_at" class="form-control form-control-sm" max="{{ now($tz)->format('Y-m-d\TH:i') }}"></div>
                            <div class="col-12"><button class="action-btn btn-primary-cb w-100 justify-content-center"><i class="ri-hand-coin-line"></i>Record payment</button></div>
                        </form>
                        @if($o->payment_status === 'pending')
                            <form method="POST" action="{{ route('store.orders.proof.reject', $o->public_id) }}" class="mt-2 d-flex gap-2">@csrf
                                <input name="reason" class="form-control form-control-sm" placeholder="Why it does not match" required maxlength="255">
                                <button class="action-btn text-danger">Not received</button>
                            </form>
                        @endif
                    </details>
                @endif

                @if($canManage && $next->isNotEmpty() && $o->status !== 'cancelled')
                    @foreach($next as $step)
                        <details class="mb-2" @if($loop->first) open @endif>
                            <summary class="fw-semibold"><i class="{{ $stepLabels[$step][1] }} me-1"></i>{{ $stepLabels[$step][0] }}</summary>
                            <form method="POST" action="{{ route('store.orders.advance', $o->public_id) }}" class="row g-2 mt-1">@csrf
                                <input type="hidden" name="to" value="{{ $step }}">
                                @if($step === 'shipped')
                                    <div class="col-6"><input name="courier" class="form-control form-control-sm" placeholder="Courier / rider" maxlength="80" value="{{ $o->courier }}"></div>
                                    <div class="col-6"><input name="tracking_number" class="form-control form-control-sm" placeholder="Tracking no." maxlength="80" value="{{ $o->tracking_number }}"></div>
                                @endif
                                @if($step === 'delivered')
                                    <div class="col-12"><input name="recipient_name" class="form-control form-control-sm" placeholder="Received by" value="{{ $o->name }}" maxlength="120"></div>
                                    @if($balance > 0.004)
                                        @if($canPay)
                                            <div class="col-6"><input type="number" step="0.01" min="0" name="collect_amount" class="form-control form-control-sm" placeholder="Collected now" value="{{ $o->payment_method === 'pay_on_delivery' ? $balance : '' }}"></div>
                                            <div class="col-6"><select name="collect_method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="card">Card / POS</option><option value="bank_transfer">Transfer</option><option value="mobile_money">Mobile money</option></select></div>
                                        @endif
                                        @if($allowBalance)<div class="col-12"><input name="outstanding" class="form-control form-control-sm" placeholder="If not paid in full: how and when the rest will be paid" maxlength="500"></div>
                                        @else<div class="col-12 small text-muted">{{ Money::format($balance) }} is still owed — collect it to hand over (house setting).</div>@endif
                                    @endif
                                @endif
                                <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="Message for the customer (optional)" maxlength="500"></div>
                                <div class="col-12"><button class="action-btn btn-primary-cb w-100 justify-content-center">{{ $stepLabels[$step][0] }}</button></div>
                            </form>
                        </details>
                    @endforeach
                @endif

                @if($o->status === 'delivered')<p class="text-success mb-0"><i class="ri-checkbox-circle-line"></i> Delivered {{ $o->delivered_at?->timezone($tz)->format('d M Y') }}.</p>@endif

                @if($canManage && $o->isOpen())
                    <details class="mt-3">
                        <summary class="text-danger small">Cancel this order</summary>
                        <form method="POST" action="{{ route('store.orders.cancel', $o->public_id) }}" class="d-flex gap-2 mt-2" onsubmit="return confirm('Cancel {{ $o->number }}? Stock goes back on sale.')">@csrf
                            <input name="reason" class="form-control form-control-sm" placeholder="Reason" required maxlength="255">
                            <button class="action-btn text-danger">Cancel</button>
                        </form>
                    </details>
                @endif
            </x-cb.card>

            <x-cb.card title="Customer" icon="ri-user-line" class="mt-3">
                <div class="fw-semibold">{{ $o->name }}</div>
                <div class="small"><a href="tel:{{ $o->phone }}">{{ $o->phone }}</a> @if($waCustomer)· <a href="https://wa.me/{{ \App\Services\Store\WhatsApp::normalize($o->phone, \App\Support\Tenancy\CurrentTenant::get()) }}" target="_blank" rel="noopener">WhatsApp</a>@endif</div>
                @if($o->email)<div class="small"><a href="mailto:{{ $o->email }}">{{ $o->email }}</a></div>@endif
                @if($o->customer)<a href="{{ route('customers.show', $o->customer) }}" class="action-btn mt-2"><i class="ri-user-heart-line"></i>{{ $o->customer->customer_number }}</a>@endif
                @if($o->account)<div class="small text-muted mt-2">Shop account since {{ $o->account->created_at->format('M Y') }}</div>@endif
                <hr>
                <div class="small text-muted text-uppercase fw-semibold mb-1">{{ $o->fulfilment === 'pickup' ? 'Pickup' : 'Delivery' }}</div>
                @if($o->fulfilment === 'pickup')<div>{{ $o->pickupBranch?->name }}</div>
                @else<div>{{ $o->address }}</div>@if($o->tracking_number)<div class="small">{{ $o->courier }} · {{ $o->tracking_number }}</div>@endif @endif
            </x-cb.card>
        </div>
    </div>
</div></div></div>

@if($o->isAwaiting() && $canManage)
<script>
document.getElementById('addVariant')?.addEventListener('change', function () {
    const o = this.selectedOptions[0]; if (!o || !o.value) return;
    const body = document.getElementById('reviseRows'); const n = body.children.length + Date.now() % 1000;
    body.insertAdjacentHTML('beforeend', `<tr><td><input type="hidden" name="items[${n}][variant_id]" value="${o.value}">${o.dataset.label}</td>
        <td><input type="number" name="items[${n}][quantity]" value="1" min="0" max="999" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.01" min="0" name="items[${n}][unit_price]" value="${o.dataset.price}" class="form-control form-control-sm" {{ $u->can('Override price') ? '' : 'readonly' }}></td><td class="text-end text-muted">new</td></tr>`);
    this.value = '';
});
</script>
@endif
@endsection
