@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php $p = $progress; $done = $p['sent'] + $p['failed'] + $p['cancelled']; $pct = $c->recipients ? round(100 * $done / $c->recipients) : 0; @endphp
    <x-cb.hero :title="$c->name" icon="ri-megaphone-line" :subtitle="\App\Models\Campaign::AUDIENCES[$c->audience['type'] ?? 'all'] . (($c->audience['days'] ?? null) ? ' (' . $c->audience['days'] . ' days)' : '') . ($tags->isNotEmpty() ? ': ' . $tags->implode(', ') : '')"
               :back="route('campaigns.index')" back-label="Campaigns">
        <x-slot:pills><span class="cb-meta-pill"><span class="status-pill {{ $c->status_pill }}">{{ $c->status_label }}</span></span>
            @if($c->send_at)<span class="cb-meta-pill"><i class="ri-time-line me-1"></i>{{ $c->send_at->timezone($tz)->format('j M Y, g:i A') }}</span>@endif</x-slot:pills>
        <x-slot:actions>
            @if($c->status === 'draft')
                <form method="post" action="{{ route('campaigns.send', $c) }}" onsubmit="return confirm('Send it now?')">@csrf<button class="cb-hero-btn"><i class="ri-send-plane-line me-1"></i>Send now</button></form>
            @endif
            @if(in_array($c->status, ['draft', 'scheduled', 'sending'], true))
                <form method="post" action="{{ route('campaigns.cancel', $c) }}" onsubmit="return confirm('Stop it? Messages already sent can’t be called back.')">@csrf<button class="cb-hero-btn">Stop</button></form>
            @endif
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Customers" :value="number_format($c->recipients)" icon="ri-team-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Sent" :value="number_format($p['sent'])" icon="ri-check-double-line" accent="teal" :hint="$c->recipients ? $pct . '% done' : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Waiting" :value="number_format($p['waiting'])" icon="ri-time-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Not sent" :value="number_format($p['failed'] + $p['cancelled'])" icon="ri-error-warning-line" accent="rose" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-4">
            <x-cb.card title="Message" icon="ri-message-2-line">
                <div style="white-space:pre-line" class="small">{{ $c->body }}{{ \App\Services\Campaigns\CampaignService::FOOTER }}</div>
                <div class="small text-muted mt-2">By {{ implode(', ', array_map(fn ($ch) => \App\Services\Messaging\CustomerUpdates::CHANNEL_LABELS[$ch] ?? $ch, $c->channels)) }} · created by {{ $c->creator?->name }}</div>
            </x-cb.card>
        </div>
        <div class="col-xl-8">
            <x-cb.card title="Customers" icon="ri-team-line" :flush="true" :count="$recipients->total()">
                @if($recipients->isEmpty())<div class="p-3 small text-muted">{{ $c->status === 'draft' ? 'Not sent yet.' : 'Nobody.' }}</div>@else
                <div class="table-responsive"><table class="cb-table stack mb-0">
                    <thead><tr><th>Customer</th><th>Status</th></tr></thead>
                    <tbody>@foreach($recipients as $r)
                        <tr><td data-label="Customer">@if($r->customer)<a href="{{ route('customers.show', $r->customer) }}">{{ $r->customer->name }}</a>@endif</td>
                            <td data-label="Status"><span class="status-pill {{ ['sent' => 'st-success', 'failed' => 'st-danger', 'cancelled' => 'st-muted'][$r->status] ?? 'st-info' }}">{{ ['sent' => 'Sent · ' . $r->result, 'failed' => 'Not sent', 'cancelled' => 'Stopped'][$r->status] ?? 'Waiting' }}</span>
                                @if($r->status === 'failed')<span class="small text-muted">{{ $r->result }}</span>@endif</td></tr>
                    @endforeach</tbody>
                </table></div>@endif
            </x-cb.card>
            <div class="mt-3">{{ $recipients->links() }}</div>
        </div>
    </div>
</div>
</div>
</div>
@endsection
