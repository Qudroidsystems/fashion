@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="Website enquiries" icon="ri-mail-open-line" subtitle="Messages, fitting requests and measurement requests sent from your website. They become customers only when you say so.">
        <x-slot:actions><a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-external-link-line me-1"></i>My website</a></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    <x-cb.card title="Enquiries" icon="ri-inbox-line" :count="$enquiries->total()" :flush="true">
        <div class="cb-toolbar">
            <ul class="nav nav-pills gap-1 flex-nowrap overflow-auto">
                @foreach(['open' => 'To answer', 'converted' => 'Became customers', 'closed' => 'Closed', 'spam' => 'Spam', 'all' => 'All'] as $k => $l)
                    @php $n = match ($k) { 'open' => ($counts['new'] ?? 0) + ($counts['contacted'] ?? 0), 'all' => $counts->sum(), default => $counts[$k] ?? 0 }; @endphp
                    <li class="nav-item"><a href="?status={{ $k }}" class="nav-link py-1 px-3 text-nowrap {{ $status === $k ? 'active' : '' }}">{{ $l }} <span class="opacity-75">{{ $n }}</span></a></li>
                @endforeach
            </ul>
        </div>
        @forelse($enquiries as $e)
            <div class="px-3 py-3 border-bottom">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                    <div>
                        <span class="fw-semibold">{{ $e->name }}</span>
                        <span class="status-pill {{ $e->status_pill }} ms-1">{{ $e->status_label }}</span>
                        <span class="small text-muted ms-1">{{ $e->type_label }} · {{ $e->created_at->timezone($tz)->format('d M, H:i') }}</span>
                        <div class="small">
                            @if($e->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $e->phone) }}">{{ $e->phone }}</a>@endif
                            @if($e->phone && $e->email) · @endif
                            @if($e->email)<a href="mailto:{{ $e->email }}">{{ $e->email }}</a>@endif
                            @if($e->style) · about <strong>{{ $e->style->name }}</strong>@endif
                            @if($e->product) · about <strong>{{ $e->product->name }}</strong>@endif
                            @if($e->preferred_date) · prefers <strong>{{ $e->preferred_date->format('D d M') }}{{ $e->preferred_time ? ', ' . strtolower($e->preferred_time) : '' }}</strong>@endif
                        </div>
                    </div>
                    <div class="d-flex gap-1 align-items-start">
                        @if($e->customer)
                            <a href="{{ route('customers.show', $e->customer) }}" class="btn btn-sm btn-light"><i class="ri-user-line me-1"></i>{{ $e->customer->name }}</a>
                        @else
                            @can('Create customer')<form method="POST" action="{{ route('site-enquiries.convert', $e) }}">@csrf<button class="btn btn-sm btn-light"><i class="ri-user-add-line me-1"></i>Make customer</button></form>@endcan
                            @if($e->status === 'new')<form method="POST" action="{{ route('site-enquiries.update', $e) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="contacted"><button class="btn btn-sm btn-light">Mark contacted</button></form>@endif
                            @if(in_array($e->status, ['new', 'contacted']))
                                <form method="POST" action="{{ route('site-enquiries.update', $e) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="closed"><button class="btn btn-sm btn-light">Close</button></form>
                                <form method="POST" action="{{ route('site-enquiries.update', $e) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="spam"><button class="btn btn-sm btn-light text-danger" title="Spam"><i class="ri-spam-2-line"></i></button></form>
                            @endif
                        @endif
                    </div>
                </div>
                @if($e->message)<p class="small mt-2 mb-0" style="white-space:pre-line">{{ $e->message }}</p>@endif
            </div>
        @empty
            <div class="empty-state"><i class="ri-inbox-line"></i><h6>Nothing here</h6><p class="small text-muted">Messages from your website will appear here.</p></div>
        @endforelse
    </x-cb.card>
    <div class="mt-3">{{ $enquiries->links() }}</div>
</div>
</div>
</div>
@endsection
