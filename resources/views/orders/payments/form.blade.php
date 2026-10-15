@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Payment; @endphp
    <x-cb.hero title="Record payment" icon="ri-bank-card-line" :subtitle="$customer ? 'From ' . $customer->name : 'Money received from a customer.'"
               :back="$order ? route('orders.show', $order) : ($invoice ? route('invoices.show', $invoice) : route('payments.index'))" back-label="Back" />
    @include('inventory.partials.flash')

    @if(! $customer)
        <x-cb.card title="Who paid?" icon="ri-user-line">
            <form method="GET" class="d-flex gap-2">
                <select name="customer" class="form-select" required><option value="">Choose customer…</option>
                    @foreach($customers as $c)<option value="{{ $c->public_id }}">{{ $c->name }} · {{ $c->customer_number }}</option>@endforeach</select>
                <button class="action-btn btn-primary-cb"><i class="ri-arrow-right-line"></i>Next</button>
            </form>
        </x-cb.card>
    @else
    <form method="POST" action="{{ route('payments.store') }}" onsubmit="this.querySelectorAll('button').forEach(b => b.disabled = true)">
        @csrf
        <input type="hidden" name="customer_id" value="{{ $customer->id }}">
        <div class="row g-3">
            <div class="col-xl-7">
                <x-cb.card title="Payment" icon="ri-wallet-3-line">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Amount *</label>
                            <div class="input-group input-group-lg"><span class="input-group-text">{{ Money::symbol() }}</span>
                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="{{ old('amount', $suggested) }}" autofocus></div></div>
                        <div class="col-md-6"><label class="form-label">How *</label>
                            <select name="method" class="form-select form-select-lg" required>
                                @foreach(Payment::METHODS as $k => $l)<option value="{{ $k }}" @selected(old('method', 'bank_transfer') === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Reference</label><input name="reference" class="form-control" maxlength="120" value="{{ old('reference') }}" placeholder="Bank / transfer reference"></div>
                        <div class="col-md-6"><label class="form-label">Received</label><input type="datetime-local" name="received_at" class="form-control" value="{{ old('received_at') }}"><div class="form-text">Leave empty for now.</div></div>
                        <div class="col-12"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="2000" value="{{ old('notes') }}"></div>
                        @if($errors->has('reference'))
                            <div class="col-12"><label class="d-flex gap-2 text-danger"><input type="checkbox" name="confirm_duplicate" value="1"> Record anyway — this is a separate payment</label></div>
                        @endif
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-5">
                <x-cb.card title="What is it for?" icon="ri-file-list-3-line">
                    @php $default = old('apply_to', $invoice ? 'invoice:' . $invoice->id : ($order ? 'order:' . $order->id : 'auto')); @endphp
                    @foreach($openInvoices as $inv)
                        <label class="d-flex gap-2 py-2 border-bottom"><input type="radio" name="apply_to" value="invoice:{{ $inv->id }}" @checked($default === 'invoice:' . $inv->id)>
                            <span class="flex-grow-1">Invoice {{ $inv->number }}<span class="d-block small text-muted">{{ $inv->order ? 'Order ' . $inv->order->number : 'Stand-alone' }}</span></span>
                            <span class="fw-semibold">{{ Money::format($inv->balance()) }}</span></label>
                    @endforeach
                    @foreach($openOrders as $o)
                        <label class="d-flex gap-2 py-2 border-bottom"><input type="radio" name="apply_to" value="order:{{ $o->id }}" @checked($default === 'order:' . $o->id)>
                            <span class="flex-grow-1">Order {{ $o->number }}<span class="d-block small text-muted">{{ $o->title }}</span></span>
                            <span class="fw-semibold">{{ Money::format($o->balance()) }}</span></label>
                    @endforeach
                    <label class="d-flex gap-2 py-2 border-bottom"><input type="radio" name="apply_to" value="auto" @checked($default === 'auto')>
                        <span>Oldest debts first</span></label>
                    <label class="d-flex gap-2 py-2"><input type="radio" name="apply_to" value="none" @checked($default === 'none')>
                        <span>Keep as credit for now</span></label>
                    <p class="small text-muted mb-3">Anything more than is owed stays as the customer’s credit.</p>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-check-line"></i>Record & print receipt</button>
                </x-cb.card>
            </div>
        </div>
    </form>
    @endif

</div>
</div>
</div>
@endsection
