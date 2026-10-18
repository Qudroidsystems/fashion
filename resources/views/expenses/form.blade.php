@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Expense; $editing = $expense->exists; @endphp
    <x-cb.hero :title="$editing ? 'Change ' . $expense->number : 'Record expense'" icon="ri-money-dollar-box-line"
               subtitle="A running cost of the house. Fabric and stock you buy go through Inventory, and wedding costs on the project, so they aren't counted twice."
               :back="route('expenses.index')" back-label="Expenses" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ $editing ? route('expenses.update', $expense) : route('expenses.store') }}" enctype="multipart/form-data"
          onsubmit="this.querySelectorAll('button').forEach(b => b.disabled = true)">
        @csrf @if($editing) @method('PUT') @endif
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Expense" icon="ri-wallet-3-line">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Amount *</label>
                            <div class="input-group input-group-lg"><span class="input-group-text">{{ Money::symbol() }}</span>
                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="{{ old('amount', $expense->amount) }}" @unless($editing) autofocus @endunless></div></div>
                        <div class="col-md-6"><label class="form-label">Date *</label>
                            <input type="date" name="spent_on" class="form-control form-control-lg" required value="{{ old('spent_on', $expense->spent_on?->toDateString()) }}"></div>
                        <div class="col-md-6"><label class="form-label">Category *</label>
                            <input name="category" class="form-control" list="expenseCategories" required maxlength="60" value="{{ old('category', $expense->category) }}" placeholder="Choose or type">
                            <datalist id="expenseCategories">@foreach($categories as $c)<option value="{{ $c }}">@endforeach</datalist></div>
                        <div class="col-md-6"><label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select"><option value="">House-wide</option>
                                @foreach($branches as $b)<option value="{{ $b->id }}" @selected((int) old('branch_id', $expense->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                        <div class="col-12"><label class="form-label">What was it for? *</label>
                            <input name="description" class="form-control" required maxlength="255" value="{{ old('description', $expense->description) }}" placeholder="e.g. Diesel for the generator, October shop rent"></div>
                        <div class="col-md-4"><label class="form-label">Paid by *</label>
                            <select name="method" class="form-select" required>@foreach(Expense::METHODS as $k => $l)<option value="{{ $k }}" @selected(old('method', $expense->method) === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Paid to</label><input name="paid_to" class="form-control" maxlength="120" value="{{ old('paid_to', $expense->paid_to) }}"></div>
                        <div class="col-md-4"><label class="form-label">Reference</label><input name="reference" class="form-control" maxlength="120" value="{{ old('reference', $expense->reference) }}"></div>
                        <div class="col-12"><label class="form-label">Receipt</label><input type="file" name="receipt" class="form-control" accept="image/*,application/pdf">
                            <div class="form-text">Photo or PDF, up to 5 MB. Kept private to people who can see expenses.@if($expense->receipt_path) A receipt is attached; a new file replaces it.@endif</div></div>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Save" icon="ri-save-line">
                    <p class="small text-muted">You can correct an expense on the day you record it. After that it can only be voided with a reason, so past months' figures don't change without a trace.</p>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-save-line"></i>{{ $editing ? 'Save changes' : 'Record expense' }}</button>
                    @unless($editing)<button name="another" value="1" class="action-btn w-100 justify-content-center py-2 mt-2"><i class="ri-add-line"></i>Record and add another</button>@endunless
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
