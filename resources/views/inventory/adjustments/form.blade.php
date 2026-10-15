@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Models\StockAdjustment;
        $kind = $adj->kind; $isNew = ! $adj->exists;
        $help = [
            'adjustment' => 'Correct the stock of an item. Use + to add and − to remove.',
            'wastage' => 'Material damaged, spoilt or lost. It is removed from stock.',
            'count' => 'Count what is physically there. Only the differences are posted, so stock that moves after you count stays correct.',
            'opening' => 'Enter stock you already had before using the system, with what it cost.',
            'customer_material' => 'Fabric or material a customer brought for their order. It is tracked but not counted in your stock value.',
        ][$kind];
        $lineCfg = [
            'adjustment' => ['lot' => 'select', 'qty' => 'quantity_change', 'qtyLabel' => 'Change (+/−)', 'signed' => true, 'note' => true],
            'wastage' => ['lot' => 'select', 'qty' => 'quantity', 'qtyLabel' => 'Quantity lost', 'note' => true],
            'opening' => ['lot' => 'text', 'qty' => 'quantity', 'qtyLabel' => 'Quantity', 'cost' => $canCost, 'lotsInStock' => false],
            'customer_material' => ['lot' => 'text', 'qty' => 'quantity', 'qtyLabel' => 'Quantity received', 'note' => true, 'lotsInStock' => false],
        ][$kind] ?? null;
    @endphp

    <x-cb.hero :title="($isNew ? 'New ' : $adj->number . ' · ') . strtolower(StockAdjustment::KINDS[$kind])" icon="ri-scales-3-line" :subtitle="$help"
               :back="$isNew ? route('stock-adjustments.index') : route('stock-adjustments.show', $adj)" :back-label="$isNew ? 'Adjustments & counts' : 'Back'" />
    @include('inventory.partials.flash')

    @if($kind === 'count' && $isNew && empty($rows))
        <x-cb.card title="What are you counting?" icon="ri-clipboard-line">
            <form method="GET" action="{{ route('stock-adjustments.create') }}" class="row g-3 align-items-end">
                <input type="hidden" name="kind" value="count"><input type="hidden" name="sheet" value="1">
                <div class="col-md-4"><label class="form-label">Location</label>
                    <select name="branch_id" class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}" @selected($adj->branch_id == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Type</label>
                    <select name="type" class="form-select"><option value="">Everything</option>@foreach($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Category</label>
                    <select name="category" class="form-select"><option value="">All</option>@foreach($categories as $c)<option>{{ $c }}</option>@endforeach</select></div>
                <div class="col-md-2 d-grid"><button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-file-list-3-line"></i>Count sheet</button></div>
            </form>
        </x-cb.card>
    @else
    <form method="POST" action="{{ $isNew ? route('stock-adjustments.store') : route('stock-adjustments.update', $adj) }}">
        @csrf @unless($isNew) @method('PUT') @endunless
        <input type="hidden" name="kind" value="{{ $kind }}">
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card :title="$kind === 'count' ? 'Count sheet' : 'Items'" icon="ri-list-check" :flush="true">
                    @if($kind === 'count')
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Item</th><th>Roll / lot</th><th class="text-end">System says</th><th style="width:180px">Counted</th></tr></thead>
                                <tbody>
                                @php $itemsById = collect($pickerItems)->keyBy('id'); @endphp
                                @foreach(old('lines', $rows) as $i => $r)
                                    @php $it = $itemsById[$r['item_id']] ?? null; @endphp
                                    @continue(! $it)
                                    <tr>
                                        <td><span class="fw-semibold">{{ $it['name'] }}</span><div class="small text-muted">{{ $it['code'] }}</div>
                                            <input type="hidden" name="lines[{{ $i }}][item_id]" value="{{ $r['item_id'] }}">
                                            <input type="hidden" name="lines[{{ $i }}][lot_id]" value="{{ $r['lot_id'] ?? '' }}"></td>
                                        <td class="small">{{ collect($it['lot_list'])->firstWhere('id', $r['lot_id'] ?? 0)['label'] ?? '—' }}</td>
                                        <td class="text-end text-muted">{{ isset($r['expected']) ? rtrim(rtrim(number_format((float) $r['expected'], $it['decimals'], '.', ''), '0'), '.') ?: '0' : '' }} {{ $it['unit'] }}</td>
                                        <td><div class="input-group input-group-sm"><input type="number" step="any" min="0" name="lines[{{ $i }}][counted_quantity]" class="form-control" value="{{ $r['counted_quantity'] ?? '' }}"><span class="input-group-text">{{ $it['unit'] }}</span></div></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted p-3 mb-0">Leave a line empty to skip it. “System says” is refreshed when you save.</p>
                    @else
                        @include('inventory.partials.lines', $lineCfg + ['items' => $pickerItems, 'rows' => old('lines', $rows), 'branch' => '#branch_id'])
                    @endif
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Details" icon="ri-information-line">
                    <label class="form-label">Location *</label>
                    @if($kind === 'count' || ! $isNew)
                        <input type="hidden" name="branch_id" id="branch_id" value="{{ $adj->branch_id }}">
                        <div class="form-control mb-3 bg-light">{{ $branches->firstWhere('id', $adj->branch_id)?->name }}</div>
                    @else
                        <select name="branch_id" id="branch_id" class="form-select mb-3" required>
                            @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $adj->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach
                        </select>
                    @endif
                    @if($kind === 'customer_material')
                        <label class="form-label">Customer *</label>
                        <select name="customer_id" class="form-select mb-3" required><option value="">Choose…</option>
                            @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $adj->customer_id) == $c->id)>{{ $c->name }} ({{ $c->customer_number }})</option>@endforeach
                        </select>
                        <p class="small text-muted">Item not listed? <a href="{{ route('inventory.items.create', ['type' => 'customer_material', 'customer' => $adj->customer_id]) }}">Add the customer’s material as an item</a> first.</p>
                    @endif
                    <label class="form-label">Reason</label>
                    <input name="reason" class="form-control mb-3" maxlength="255" value="{{ old('reason', $adj->reason) }}" placeholder="{{ ['count' => 'e.g. Month-end count', 'wastage' => 'e.g. Water damage in store', 'opening' => 'e.g. Stock at go-live', 'customer_material' => 'e.g. For her wedding gown', 'adjustment' => 'e.g. Miscounted delivery'][$kind] }}">
                    <div class="d-grid gap-2">
                        <button name="action" value="save" class="action-btn justify-content-center py-2"><i class="ri-save-line"></i>Save draft</button>
                        <button name="action" value="submit" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Submit</button>
                    </div>
                    <p class="small text-muted mt-2 mb-0">If you may approve and the value is within the limit, submitting posts it straight away.</p>
                </x-cb.card>
            </div>
        </div>
    </form>
    @endif

</div>
</div>
</div>
@endsection
