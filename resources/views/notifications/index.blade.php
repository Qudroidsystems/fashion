@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Notifications" icon="ri-notification-3-line" subtitle="Alerts and updates for you in this fashion house."
               :back="route('dashboard')" back-label="Dashboard" />

    <x-cb.card title="All notifications" icon="ri-inbox-line" :count="$items->total()" :flush="true">
        @forelse($items as $n)
            <a href="{{ $n->data['url'] ?? '#' }}" class="d-flex gap-3 px-4 py-3 border-bottom text-decoration-none"
               style="color:inherit;{{ $n->read_at ? '' : 'background:rgba(13,148,136,.06);' }}">
                <i class="{{ $n->data['icon'] ?? 'ri-notification-3-line' }} fs-4" style="color:var(--cb-teal)"></i>
                <span class="flex-grow-1">
                    <span class="d-block fw-semibold">{{ $n->data['title'] ?? 'Notification' }}</span>
                    <span class="d-block text-muted small">{{ $n->data['body'] ?? '' }}</span>
                </span>
                <span class="text-muted small text-nowrap">{{ $n->created_at->diffForHumans() }}</span>
            </a>
        @empty
            <div class="empty-state">
                <i class="ri-notification-off-line"></i>
                <h6>No notifications yet</h6>
                <p>Order updates, fittings, payments and reminders will appear here.</p>
            </div>
        @endforelse
        @if($items->hasPages())
            <div class="px-4 py-3">{{ $items->links() }}</div>
        @endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
