@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; @endphp
    <x-cb.hero title="Take an order" icon="ri-whatsapp-line" subtitle="For orders that come in on WhatsApp, Instagram or the phone. The customer gets a private link to see the order and pay." :back="route('store.orders.index')" back-label="Online orders" />
    @include('store-admin.nav')
    @include('inventory.partials.flash')

    <form method="POST" action="{{ route('store.orders.store') }}" id="takeOrder">
        @csrf
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Items" icon="ri-t-shirt-air-line" :flush="true">
                    <div class="p-3 pb-0">
                        <label class="form-label">Find a product</label>
                        <input class="form-control" list="variantList" id="variantSearch" placeholder="Type a name, size or SKU, then choose…">
                        <datalist id="variantList">
                            @foreach($variants as $v)<option value="{{ $v['name'] }}{{ $v['label'] !== 'Standard' ? ' — ' . $v['label'] : '' }} [{{ $v['sku'] }}]">{{ Money::format($v['price']) }}{{ $v['stock'] !== null ? ' · ' . $v['stock'] . ' free' : '' }}</option>@endforeach
                        </datalist>
                    </div>
                    <div class="table-responsive"><table class="table align-middle mb-0 mt-2">
                        <thead class="table-light"><tr><th>Item</th><th style="width:100px">Qty</th><th style="width:150px">Price</th><th style="width:40px"></th></tr></thead>
                        <tbody id="itemRows">
                            @foreach(old('items', []) as $i => $row)
                                @php $v = $variants->firstWhere('id', (int) $row['variant_id']); @endphp
                                @if($v)<tr><td><input type="hidden" name="items[{{ $i }}][variant_id]" value="{{ $v['id'] }}">{{ $v['name'] }} <span class="text-muted small">{{ $v['label'] }}</span></td>
                                    <td><input type="number" name="items[{{ $i }}][quantity]" value="{{ $row['quantity'] }}" min="1" class="form-control form-control-sm"></td>
                                    <td><input type="number" step="0.01" name="items[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? $v['price'] }}" class="form-control form-control-sm" {{ $canOverride ? '' : 'readonly' }}></td>
                                    <td><button type="button" class="btn btn-sm btn-light text-danger js-del">×</button></td></tr>@endif
                            @endforeach
                        </tbody>
                    </table></div>
                    <p class="small text-muted p-3 mb-0" id="noItems">Add the items the customer asked for. Prices come from the shop{{ $canOverride ? ' (you can change them)' : '' }}.</p>
                </x-cb.card>

                <x-cb.card title="Delivery" icon="ri-truck-line" class="mt-3">
                    <div class="d-flex gap-3 mb-3">
                        <label class="d-flex gap-2"><input type="radio" name="fulfilment" value="pickup" @checked(old('fulfilment', 'pickup') === 'pickup')> Pickup</label>
                        <label class="d-flex gap-2"><input type="radio" name="fulfilment" value="delivery" @checked(old('fulfilment') === 'delivery')> Delivery</label>
                    </div>
                    <div class="row g-2" id="pickupBox">
                        <div class="col-md-6"><label class="form-label">Pickup branch</label><select name="pickup_branch_id" class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('pickup_branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                    </div>
                    <div class="row g-2 d-none" id="deliveryBox">
                        <div class="col-md-8"><label class="form-label">Address</label><input name="address_line1" class="form-control" value="{{ old('address_line1') }}" maxlength="190"></div>
                        <div class="col-md-4"><label class="form-label">Landmark</label><input name="address_line2" class="form-control" value="{{ old('address_line2') }}" maxlength="190"></div>
                        <div class="col-md-4"><label class="form-label">Town / city</label><input name="city" class="form-control" value="{{ old('city') }}" maxlength="80"></div>
                        <div class="col-md-4"><label class="form-label">State</label>
                            @if($states)<select name="state" class="form-select"><option value="">—</option>@foreach($states as $s)<option @selected(old('state') === $s)>{{ $s }}</option>@endforeach</select>
                            @else<input name="state" class="form-control" value="{{ old('state') }}">@endif</div>
                        <div class="col-md-4"><label class="form-label">Delivery fee <span class="small text-muted">(blank = zone fee)</span></label><input type="number" step="0.01" min="0" name="shipping_fee" class="form-control" value="{{ old('shipping_fee') }}"></div>
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Customer" icon="ri-user-line">
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label">First name *</label><input name="first_name" class="form-control" required maxlength="80" value="{{ old('first_name') }}"></div>
                        <div class="col-6"><label class="form-label">Last name</label><input name="last_name" class="form-control" maxlength="80" value="{{ old('last_name') }}"></div>
                        <div class="col-12"><label class="form-label">WhatsApp / phone *</label><input name="phone" class="form-control" required maxlength="30" value="{{ old('phone') }}"></div>
                        <div class="col-12"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" maxlength="160" value="{{ old('email') }}"></div>
                        <div class="col-12"><label class="form-label">Came in through</label><select name="source" class="form-select">@foreach(['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'phone' => 'Phone call', 'walk_in' => 'Walk-in', 'other' => 'Other'] as $k => $l)<option value="{{ $k }}" @selected(old('source') === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-12"><label class="form-label">Note</label><textarea name="customer_note" class="form-control" rows="2" maxlength="1000">{{ old('customer_note') }}</textarea></div>
                    </div>
                </x-cb.card>
                <x-cb.card title="Payment" icon="ri-bank-card-line" class="mt-3">
                    <label class="form-label">How the customer will pay</label>
                    <select name="payment_method" class="form-select mb-2">@foreach($methods as $k => $l)<option value="{{ $k }}" @selected(old('payment_method', 'bank_transfer') === $k)>{{ $l }}</option>@endforeach</select>
                    <label class="form-label">Discount</label><input type="number" step="0.01" min="0" name="discount" class="form-control mb-2" value="{{ old('discount') }}">
                    @can('Confirm online payment')
                        <details class="mb-2"><summary class="small fw-semibold">Already paid?</summary>
                            <div class="row g-2 mt-1">
                                <div class="col-6"><input type="number" step="0.01" min="0" name="paid_amount" class="form-control form-control-sm" placeholder="Amount" value="{{ old('paid_amount') }}"></div>
                                <div class="col-6"><select name="paid_method" class="form-select form-select-sm"><option value="bank_transfer">Transfer</option><option value="cash">Cash</option><option value="card">Card / POS</option><option value="mobile_money">Mobile money</option></select></div>
                                <div class="col-12"><input name="paid_reference" class="form-control form-control-sm" placeholder="Reference" maxlength="120" value="{{ old('paid_reference') }}"></div>
                            </div>
                        </details>
                    @endcan
                    <label class="d-flex gap-2 mb-3 small"><input type="hidden" name="confirm" value="0"><input type="checkbox" name="confirm" value="1" @checked(old('confirm'))> Confirm now (the customer has agreed)</label>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-save-line"></i>Save order</button>
                </x-cb.card>
            </div>
        </div>
    </form>
</div></div></div>

<script>
(function () {
    const variants = @json($variants);
    const canOverride = @json($canOverride);
    const rows = document.getElementById('itemRows');
    const search = document.getElementById('variantSearch');
    let n = 1000;
    const money = v => new Intl.NumberFormat().format(v);
    function refresh() { document.getElementById('noItems').classList.toggle('d-none', rows.children.length > 0); }
    search.addEventListener('change', () => {
        const m = search.value.match(/\[([^\]]+)\]$/); if (!m) return;
        const v = variants.find(x => x.sku === m[1]); if (!v) return;
        const existing = rows.querySelector(`input[value="${v.id}"][name$="[variant_id]"]`);
        if (existing) { const q = existing.closest('tr').querySelector('input[name$="[quantity]"]'); q.value = +q.value + 1; }
        else rows.insertAdjacentHTML('beforeend', `<tr><td><input type="hidden" name="items[${n}][variant_id]" value="${v.id}">${v.name} <span class="text-muted small">${v.label === 'Standard' ? '' : v.label}</span>${v.stock !== null ? `<div class="small ${v.stock > 0 ? 'text-muted' : 'text-danger'}">${v.stock} free</div>` : ''}</td>
            <td><input type="number" name="items[${n}][quantity]" value="1" min="1" class="form-control form-control-sm"></td>
            <td><input type="number" step="0.01" name="items[${n}][unit_price]" value="${v.price}" class="form-control form-control-sm" ${canOverride ? '' : 'readonly'}></td>
            <td><button type="button" class="btn btn-sm btn-light text-danger js-del">×</button></td></tr>`);
        n++; search.value = ''; refresh();
    });
    rows.addEventListener('click', e => { if (e.target.closest('.js-del')) { e.target.closest('tr').remove(); refresh(); } });
    const sync = () => { const d = document.querySelector('input[name=fulfilment]:checked').value === 'delivery';
        document.getElementById('deliveryBox').classList.toggle('d-none', !d); document.getElementById('pickupBox').classList.toggle('d-none', d); };
    document.querySelectorAll('input[name=fulfilment]').forEach(r => r.addEventListener('change', sync));
    sync(); refresh();
})();
</script>
@endsection
