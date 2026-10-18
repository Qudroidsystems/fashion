@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Expense; @endphp
    <x-cb.hero title="Expenses" icon="ri-money-dollar-box-line" subtitle="What the house spends to run: rent, salaries, power, transport… These feed the profit report.">
        <x-slot:actions>
            @can('Manage expense')<a href="{{ route('expenses.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>Record expense</a>@endcan
            @if(Route::has('reports.show'))@can('View financial reports')<a href="{{ route('reports.show', 'expenses') }}" class="cb-hero-btn"><i class="ri-bar-chart-box-line me-1"></i>Expense report</a>@endcan @endif
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Spent in this period" :value="Money::format($total)" icon="ri-money-dollar-box-line" accent="rose" :hint="$from->format('j M') . ' – ' . $to->format('j M Y')" /></div>
        @foreach($byCategory->take(3) as $c)
            <div class="col-6 col-lg-3"><x-cb.stat :label="$c->category" :value="Money::format($c->total)" icon="ri-price-tag-3-line" accent="violet" :hint="$c->n . ' expense(s)'" /></div>
        @endforeach
    </div>

    <x-cb.card title="Expenses" icon="ri-list-check" :count="$expenses->total()" :flush="true">
        <form method="GET" class="cb-toolbar flex-wrap">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Description, paid to or number…"></div>
            <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
            <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
            <select name="category" class="cb-select" onchange="this.form.submit()"><option value="">All categories</option>
                @foreach($categories as $c)<option @selected(request('category') === $c)>{{ $c }}</option>@endforeach</select>
            @if($branches->count() > 1)
            <select name="branch" class="cb-select" onchange="this.form.submit()"><option value="">All branches</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected((int) request('branch') === $b->id)>{{ $b->name }}</option>@endforeach</select>
            @endif
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Show</button>
        </form>
        @if($expenses->isEmpty())
            <div class="empty-state"><i class="ri-money-dollar-box-line"></i><h6>No expenses in this period</h6>
                @can('Manage expense')<p class="text-muted small mb-0">Record rent, salaries, diesel and other running costs so the profit report is complete.</p>@endcan</div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Date</th><th>What</th><th>Category</th><th>Paid</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
            @foreach($expenses as $e)
                <tr class="{{ $e->status === 'void' ? 'opacity-50' : '' }}">
                    <td data-label="Date" class="text-nowrap">{{ $e->spent_on->format('d M Y') }}<div class="small text-muted">{{ $e->number }}</div></td>
                    <td data-label="What"><div class="fw-semibold">{{ $e->description }}</div>
                        <div class="small text-muted">@if($e->paid_to)to {{ $e->paid_to }} · @endif{{ $e->branch?->name ?? 'House-wide' }} · by {{ $e->recorder?->name }}</div>
                        @if($e->status === 'void')<div class="small text-danger">Voided: {{ $e->void_reason }}</div>@endif</td>
                    <td data-label="Category"><span class="status-pill st-muted">{{ $e->category }}</span></td>
                    <td data-label="Paid" class="small">{{ Expense::METHODS[$e->method] ?? $e->method }}@if($e->reference)<div class="text-muted">{{ $e->reference }}</div>@endif</td>
                    <td data-label="Amount" class="text-end fw-semibold text-nowrap">{{ Money::format($e->amount) }}@if($e->status === 'void')<div><span class="status-pill st-danger">Void</span></div>@endif</td>
                    <td class="text-end text-nowrap">
                        @if($e->receipt_path)<a href="{{ route('expenses.receipt', $e) }}" target="_blank" class="action-btn" title="Receipt"><i class="ri-attachment-2"></i></a>@endif
                        @can('Manage expense')
                            @if($e->isEditable())<a href="{{ route('expenses.edit', $e) }}" class="action-btn" title="Change"><i class="ri-pencil-line"></i></a>@endif
                            @if($e->status === 'recorded')
                                <button type="button" class="action-btn" title="Void" data-bs-toggle="collapse" data-bs-target="#void{{ $e->id }}"><i class="ri-close-circle-line"></i></button>
                            @endif
                        @endcan
                    </td>
                </tr>
                @can('Manage expense')@if($e->status === 'recorded')
                <tr class="collapse" id="void{{ $e->id }}"><td colspan="6">
                    <form method="POST" action="{{ route('expenses.void', $e) }}" class="d-flex gap-2 justify-content-end">@csrf
                        <input name="reason" class="form-control form-control-sm" style="max-width:320px" required maxlength="255" placeholder="Why is this being voided?">
                        <button class="action-btn btn-danger-soft"><i class="ri-close-circle-line"></i>Void {{ $e->number }}</button></form>
                </td></tr>
                @endif @endcan
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $expenses->links() }}</div>

</div>
</div>
</div>
@endsection
