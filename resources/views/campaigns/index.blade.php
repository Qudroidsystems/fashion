@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="Campaigns" icon="ri-megaphone-line" subtitle="Festive offers, a new collection, a reminder that you’re open — one message to the customers who said yes to promotions.">
        <x-slot:actions><a href="{{ route('campaigns.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New campaign</a></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <x-cb.card :flush="true" title="All campaigns" icon="ri-megaphone-line" :count="$campaigns->total()">
        @if($campaigns->isEmpty())
            <div class="empty-state"><i class="ri-megaphone-line"></i><h6>No campaigns yet</h6>
                <p class="small text-muted">Customers are only messaged if they agreed to promotions — record that on each customer under Contact consent.</p></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Campaign</th><th>When</th><th class="text-end">Customers</th><th class="text-end">Sent</th><th>Status</th></tr></thead>
            <tbody>@foreach($campaigns as $c)
                <tr onclick="location.href='{{ route('campaigns.show', $c) }}'" style="cursor:pointer">
                    <td data-label="Campaign"><a href="{{ route('campaigns.show', $c) }}" class="fw-semibold">{{ $c->name }}</a><div class="small text-muted text-truncate" style="max-width:360px">{{ $c->body }}</div></td>
                    <td data-label="When" class="text-nowrap">{{ $c->send_at ? $c->send_at->timezone($tz)->format('j M, g:i A') : '—' }}</td>
                    <td data-label="Customers" class="text-end">{{ number_format($c->recipients) }}</td>
                    <td data-label="Sent" class="text-end">{{ number_format($c->progress['sent']) }}@if($c->progress['failed'])<span class="text-danger small d-block">{{ $c->progress['failed'] }} not sent</span>@endif</td>
                    <td data-label="Status"><span class="status-pill {{ $c->status_pill }}">{{ $c->status_label }}</span></td>
                </tr>
            @endforeach</tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $campaigns->links() }}</div>
</div>
</div>
</div>
@endsection
