@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Support\Money; use App\Support\Billing\Features; $plan = $sub->plan; $end = $sub->endsAt(); @endphp
    <x-cb.hero title="Plan & billing" icon="ri-vip-crown-line" subtitle="Your plan, what it includes, and your receipts. Changing plan never deletes anything." />
    @include('inventory.partials.flash')

    @if($sub->status === 'past_due')
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>We could not take your renewal payment{{ $sub->failure_reason ? ' (' . $sub->failure_reason . ')' : '' }}.
            You keep {{ $plan->name }} until {{ $sub->grace_ends_at?->timezone($tz)->format('j M Y') }}; pay below to keep it.</div></div>
    @elseif($sub->status === 'trialing')
        <div class="cb-banner warning"><i class="ri-time-line"></i><div>You are trying {{ $plan->name }} free until {{ $sub->trial_ends_at?->timezone($tz)->format('j M Y') }}
            ({{ max(0, (int) now()->diffInDays($sub->trial_ends_at, false)) }} days left). Choose a plan to keep its features; otherwise you move to the Free plan.</div></div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <x-cb.card title="Your plan" icon="ri-vip-crown-line">
                <div class="d-flex justify-content-between align-items-start">
                    <div><div class="fs-3 fw-bold" style="color:var(--cb-heading)">{{ $plan->name }}</div>
                        <div class="text-muted small">{{ $plan->tagline }}</div></div>
                    <span class="status-pill {{ $sub->status_pill }}">{{ $sub->status_label }}</span>
                </div>
                <div class="mt-3 small">
                    @if($plan->isPaid() && $sub->status !== 'trialing')
                        <div>{{ Money::format($plan->price($sub->interval)) }} a {{ $sub->interval === 'yearly' ? 'year' : 'month' }}</div>
                        @if($sub->current_period_end)<div class="text-muted">{{ $sub->cancel_at_period_end ? 'Ends' : 'Renews' }} {{ $sub->current_period_end->timezone($tz)->format('j M Y') }}{{ $sub->card_label && ! $sub->cancel_at_period_end ? ' · ' . $sub->card_label : '' }}</div>@endif
                    @elseif(! $plan->isPaid())
                        <div class="text-muted">Free, no end date.</div>
                    @endif
                    @if($sub->pendingPlan)<div class="text-warning mt-1"><i class="ri-arrow-down-line"></i> Moving to {{ $sub->pendingPlan->name }} on {{ $sub->current_period_end?->timezone($tz)->format('j M Y') }}.</div>@endif
                    @if($sub->cancel_at_period_end)<div class="text-warning mt-1"><i class="ri-information-line"></i> Will not renew; you move to the Free plan after that date.</div>@endif
                </div>
                @if($plan->isPaid() && $sub->status === 'active' && $sub->current_period_end && ! $sub->cancel_at_period_end)
                    <form method="POST" action="{{ route('billing.cancel') }}" class="mt-3" onsubmit="return confirm('Stop renewing? You keep {{ $plan->name }} until the end of the paid period.')">@csrf
                        <button class="btn btn-sm btn-light text-danger">Stop renewing</button></form>
                @endif
            </x-cb.card>
        </div>
        <div class="col-lg-7">
            <x-cb.card title="What you are using" icon="ri-bar-chart-horizontal-line">
                @foreach($usage as $key => $u)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small"><span>{{ $u['label'] }}</span>
                            <span class="{{ $u['limit'] && $u['used'] >= $u['limit'] ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $u['used'] }} of {{ $u['limit'] ?? 'unlimited' }}</span></div>
                        @if($u['limit'])<div class="progress mt-1" style="height:6px"><div class="progress-bar {{ $u['percent'] >= 100 ? 'bg-danger' : ($u['percent'] >= 80 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $u['percent'] }}%"></div></div>@endif
                    </div>
                @endforeach
                <p class="small text-muted mb-0">At the limit you can still open and edit everything you have; only adding new ones waits for an upgrade.</p>
            </x-cb.card>
        </div>
    </div>

    <x-cb.card title="Plans" icon="ri-price-tag-3-line">
        <x-slot:tools>
            <div class="btn-group btn-group-sm" role="group" aria-label="Billing period">
                <input type="radio" class="btn-check" name="period" id="pm" value="monthly" @checked($sub->interval !== 'yearly')><label class="btn btn-outline-primary" for="pm">Monthly</label>
                <input type="radio" class="btn-check" name="period" id="py" value="yearly" @checked($sub->interval === 'yearly')><label class="btn btn-outline-primary" for="py">Yearly (2 months free)</label>
            </div>
        </x-slot:tools>
        @unless($canPay)<div class="cb-banner warning mb-3"><i class="ri-information-line"></i><div>Paying online for plans is not switched on yet. Contact us to change plan.</div></div>@endunless
        <div class="row g-3">
            @foreach($plans as $p)
                @php $isCurrent = $p->id === $plan->id; @endphp
                <div class="col-md-6 col-xl-3">
                    <div class="h-100 p-3 rounded-3 d-flex flex-column" style="border:{{ $p->highlight ? '2px solid var(--cb-teal)' : '1px solid var(--cb-border)' }};background:var(--cb-surface)">
                        <div class="d-flex justify-content-between align-items-center"><span class="fw-bold fs-5">{{ $p->name }}</span>
                            @if($isCurrent)<span class="status-pill st-success">Your plan</span>@elseif($p->highlight)<span class="status-pill st-info">Most chosen</span>@endif</div>
                        <div class="small text-muted mb-2">{{ $p->tagline }}</div>
                        <div class="fs-4 fw-semibold" data-price data-monthly="{{ $p->price_monthly > 0 ? Money::format($p->price_monthly) . ' / month' : 'Free' }}" data-yearly="{{ $p->price_yearly > 0 ? Money::format($p->price_yearly) . ' / year' : 'Free' }}">
                            {{ $p->price_monthly > 0 ? Money::format($p->price_monthly) . ' / month' : 'Free' }}</div>
                        <ul class="list-unstyled small mt-3 mb-3 flex-grow-1">
                            @foreach(Features::LIMITS as $k => $l)<li class="mb-1"><i class="ri-user-line text-muted me-1"></i>{{ $p->limit($k) === null ? 'Unlimited ' . strtolower($l[2]) : number_format($p->limit($k)) . ' ' . ($p->limit($k) === 1 ? $l[1] : $l[2]) }}</li>@endforeach
                            @foreach($p->featureLabels() as $f)<li class="mb-1"><i class="ri-check-line text-success me-1"></i>{{ $f }}</li>@endforeach
                        </ul>
                        <form method="POST" action="{{ route('billing.choose') }}" class="plan-form">@csrf
                            <input type="hidden" name="plan" value="{{ $p->code }}"><input type="hidden" name="interval" value="{{ $sub->interval }}">
                            @php
                                $label = match (true) {
                                    $isCurrent && $sub->status === 'past_due' => 'Pay now',
                                    $isCurrent && $sub->status === 'trialing' && $p->isPaid() => 'Pay to keep ' . $p->name,
                                    $isCurrent && ($sub->cancel_at_period_end || $sub->pending_plan_id) => 'Keep ' . $p->name,
                                    $isCurrent => null,
                                    $p->sort_order < $plan->sort_order => 'Move to ' . $p->name,
                                    default => 'Choose ' . $p->name,
                                };
                            @endphp
                            @if($label)<button class="action-btn {{ $p->sort_order > $plan->sort_order || $isCurrent ? 'btn-primary-cb' : '' }} w-100 justify-content-center" @disabled(! $canPay && $p->isPaid() && ($p->sort_order > $plan->sort_order || ! $plan->isPaid() || ($isCurrent && in_array($sub->status, ['past_due', 'trialing']))))>{{ $label }}</button>@endif
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="small text-muted mt-3 mb-0">Upgrading starts a new period today. Moving to a smaller plan happens when your paid time ends. Renewals use the card you paid with; if a renewal fails you keep your plan for {{ config('billing.grace_days') }} days to sort it out.</p>
    </x-cb.card>

    <x-cb.card title="Receipts" icon="ri-file-list-3-line" class="mt-3" :flush="true">
        @if($invoices->isEmpty())
            <p class="small text-muted p-3 mb-0">No payments yet.</p>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Invoice</th><th>Plan</th><th>Period</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($invoices as $i)
                <tr><td data-label="Invoice"><span class="fw-semibold">{{ $i->number }}</span><div class="small text-muted">{{ $i->created_at->timezone($tz)->format('d M Y') }}{{ $i->channel ? ' · ' . $i->channel : '' }}</div></td>
                    <td data-label="Plan">{{ $i->plan?->name }} · {{ $i->interval }}</td>
                    <td data-label="Period" class="small">{{ $i->period_start ? $i->period_start->timezone($tz)->format('j M Y') . ' – ' . $i->period_end?->timezone($tz)->format('j M Y') : '—' }}</td>
                    <td data-label="Amount" class="text-end">{{ Money::format($i->amount) }}</td>
                    <td data-label="Status"><span class="status-pill {{ $i->status_pill }}">{{ $i->status_label }}</span>@if($i->failure_reason)<div class="small text-muted">{{ $i->failure_reason }}</div>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
</div>
</div>
</div>
@push('scripts')
<script>
document.querySelectorAll('input[name=period]').forEach(r => r.addEventListener('change', () => {
    const v = document.querySelector('input[name=period]:checked').value;
    document.querySelectorAll('[data-price]').forEach(el => el.textContent = el.dataset[v]);
    document.querySelectorAll('.plan-form input[name=interval]').forEach(i => i.value = v);
}));
document.querySelector('input[name=period]:checked')?.dispatchEvent(new Event('change'));
</script>
@endpush
@endsection
