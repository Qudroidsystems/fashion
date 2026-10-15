@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Support\Money; use App\Models\SaleTender; $u = auth()->user(); $mine = $shift->user_id === $u->id; $sm = $summary;
        // Blind count: a cashier doesn't see the expected cash on their own open shift.
        $blind = $shift->isOpen() && ! $u->can('Void sale'); @endphp
    <x-cb.hero :title="'Shift ' . $shift->number" icon="ri-safe-2-line" :subtitle="$shift->cashier->name . ' · ' . $shift->branch->name"
               :back="route('pos.shifts.index')" back-label="Shifts">
        <x-slot:pills>
            <span class="status-pill {{ $shift->isOpen() ? 'st-warning' : 'st-muted' }}">{{ $shift->isOpen() ? 'Open' : 'Closed' }}</span>
            <span class="cb-meta-pill">Opened {{ $shift->opened_at->timezone($tz)->format('d M, H:i') }}</span>
            @if($shift->closed_at)<span class="cb-meta-pill">Closed {{ $shift->closed_at->timezone($tz)->format('d M, H:i') }} by {{ $shift->closer?->name }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($shift->isOpen() && $mine)<a href="{{ route('pos.terminal') }}" class="cb-hero-btn"><i class="ri-shopping-cart-2-line me-1"></i>Back to till</a>@endif
        </x-slot:actions>
    </x-cb.hero>
    @include('pos.partials-nav')
    @include('inventory.partials.flash')
    @if(! $shift->isOpen())
        <div class="cb-banner {{ $shift->variance == 0 ? 'success' : 'warning' }}"><i class="ri-scales-3-line"></i><div>
            Expected {{ Money::format($shift->expected_cash) }}, counted {{ Money::format($shift->counted_cash) }} —
            {{ $shift->variance == 0 ? 'the drawer balanced.' : ($shift->variance < 0 ? 'short by ' : 'over by ') . Money::format(abs($shift->variance)) . '. ' . $shift->variance_reason }}
        </div></div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Sales" :value="Money::format($sm['sales_total'])" icon="ri-receipt-line" accent="teal" :hint="$sm['sales_count'] . ' sale(s)' . ($sm['voided_count'] ? ', ' . $sm['voided_count'] . ' voided' : '')" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Cash in drawer (expected)" :value="$blind ? 'Hidden' : Money::format($sm['expected_cash'])" icon="ri-safe-2-line" accent="violet" :hint="$blind ? 'Shown after you count' : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Opening float" :value="Money::format($shift->opening_float)" icon="ri-coins-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Refunds" :value="Money::format(collect($sm['refunds'])->sum())" icon="ri-arrow-go-back-line" accent="rose" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Takings by method" icon="ri-wallet-3-line">
                @foreach(SaleTender::METHODS as $k => $l)
                    @if(($sm['tenders'][$k] ?? 0) > 0 || ($sm['refunds'][$k] ?? 0) > 0)
                        <div class="d-flex justify-content-between py-1 border-bottom"><span>{{ $l }}</span>
                            <span><span class="fw-semibold">{{ $blind && $k === 'cash' ? 'Hidden' : Money::format($sm['tenders'][$k] ?? 0) }}</span>@if(($sm['refunds'][$k] ?? 0) > 0)<span class="text-danger small ms-2">−{{ Money::format($sm['refunds'][$k]) }} refunded</span>@endif</span></div>
                    @endif
                @endforeach
                @if(($sm['refunds']['store_credit'] ?? 0) > 0)<div class="small text-muted mt-1">Store credit given: {{ Money::format($sm['refunds']['store_credit']) }}</div>@endif
                @unless($blind)<div class="small text-muted mt-2">Cash = float {{ Money::format($shift->opening_float) }} + cash sales {{ Money::format($sm['tenders']['cash'] ?? 0) }} − cash refunds {{ Money::format($sm['refunds']['cash'] ?? 0) }} + paid in {{ Money::format($sm['pay_in']) }} − paid out {{ Money::format($sm['pay_out']) }}.</div>@endunless
            </x-cb.card>

            <x-cb.card title="Sales" icon="ri-receipt-line" class="mt-3" :flush="true">
                @forelse($sales as $s)
                    <a href="{{ route('pos.sales.show', $s) }}" class="d-flex justify-content-between px-3 py-2 border-bottom text-body {{ $s->status === 'voided' ? 'opacity-50' : '' }}">
                        <span><span class="fw-semibold">{{ $s->number }}</span> <span class="small text-muted">{{ $s->created_at->timezone($tz)->format('H:i') }} · {{ $s->customer?->name ?? 'Walk-in' }}</span></span>
                        <span>{{ Money::format($s->total) }}@if($s->status === 'voided') <span class="status-pill st-danger">Voided</span>@endif</span></a>
                @empty
                    <p class="small text-muted p-3 mb-0">No sales yet.</p>
                @endforelse
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            @if($shift->isOpen() && $mine)
                <x-cb.card title="Cash in / out" icon="ri-exchange-dollar-line">
                    <form method="POST" action="{{ route('pos.shifts.cash', $shift) }}" class="row g-2">@csrf
                        <div class="col-5"><select name="type" class="form-select form-select-sm"><option value="pay_out">Take out</option><option value="pay_in">Put in</option></select></div>
                        <div class="col-7"><input type="number" step="0.01" min="1" name="amount" class="form-control form-control-sm" required placeholder="Amount"></div>
                        <div class="col-12"><input name="reason" class="form-control form-control-sm" required maxlength="255" placeholder="Why? e.g. Bought thread, bank deposit"></div>
                        <div class="col-12"><button class="action-btn w-100 justify-content-center">Record</button></div>
                    </form>
                </x-cb.card>
            @endif
            @if($shift->movements->isNotEmpty())
                <x-cb.card title="Cash movements" icon="ri-exchange-dollar-line" class="mt-3">
                    @foreach($shift->movements as $m)
                        <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $m->reason }}<span class="d-block text-muted">{{ $m->created_at->timezone($tz)->format('H:i') }} · {{ $m->user?->name }}</span></span>
                            <span class="{{ $m->type === 'pay_out' ? 'text-danger' : 'text-success' }}">{{ $m->type === 'pay_out' ? '−' : '+' }}{{ Money::format($m->amount) }}</span></div>
                    @endforeach
                </x-cb.card>
            @endif
            @if($shift->isOpen() && ($mine || $u->can('Void sale')) && $u->can('Close cash shift'))
                <x-cb.card title="Close shift" icon="ri-lock-line" class="mt-3">
                    <form method="POST" action="{{ route('pos.shifts.close', $shift) }}">@csrf
                        <label class="form-label">Cash counted in the drawer</label>
                        <div class="input-group mb-2"><span class="input-group-text">{{ Money::symbol() }}</span><input type="number" step="0.01" min="0" name="counted_cash" class="form-control" required></div>
                        <input name="variance_reason" class="form-control form-control-sm mb-2" maxlength="255" placeholder="If it doesn't match, why?">
                        <button class="action-btn btn-primary-cb w-100 justify-content-center py-2" onclick="return confirm('Close this shift?')"><i class="ri-lock-line"></i>Count &amp; close</button>
                        <p class="small text-muted mt-2 mb-0">{{ $blind ? 'Count the drawer first — the expected amount is shown after closing, so the count stays honest.' : 'Count the drawer and enter what is actually there.' }}</p>
                    </form>
                </x-cb.card>
            @endif
        </div>
    </div>
</div>
</div>
</div>
@endsection
