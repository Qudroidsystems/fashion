{{-- Order page: what the customer brought for this order. Expects $order. --}}
@can('View customer materials')
@php
    $omats = \App\Models\CustomerMaterial::where('order_id', $order->id)->orderByRaw("case when status in ('held','in_use') then 0 else 1 end")->latest('received_at')->get();
    $oOther = \App\Models\CustomerMaterial::withHouse()->where('customer_id', $order->customer_id)->whereNull('order_id')->count();
@endphp
<x-cb.card title="Customer’s materials" icon="ri-archive-drawer-line" :count="$omats->count()" class="mt-3">
    <x-slot:tools>
        @can('Manage customer materials')<a href="{{ route('materials.create', ['order' => $order->public_id]) }}" class="btn btn-light btn-sm"><i class="ri-add-line"></i> Receive</a>@endcan
    </x-slot:tools>
    @forelse($omats as $m)
        <a href="{{ route('materials.show', $m) }}" class="d-flex align-items-center gap-2 py-2 border-bottom text-reset text-decoration-none">
            @if($m->photos)<img src="{{ $m->photoUrls()[0] }}" alt="" width="36" height="36" class="rounded object-fit-cover">@else<i class="ri-t-shirt-line fs-4 text-muted"></i>@endif
            <div class="flex-grow-1 small"><div class="fw-semibold">{{ $m->number }} · {{ $m->summary }}</div><div class="text-muted">{{ $m->location ?: 'No shelf noted' }}</div></div>
            <span class="status-pill {{ \App\Models\CustomerMaterial::STATUSES[$m->status][1] ?? 'st-muted' }}">{{ $m->status_label }}</span>
        </a>
    @empty
        <p class="small text-muted mb-0">Did the customer bring fabric or a garment for this order? Receive it here so it gets a tag and goes back to them.</p>
    @endforelse
    @if($oOther)<p class="small text-muted mt-2 mb-0">{{ $oOther }} more {{ \Illuminate\Support\Str::plural('item', $oOther) }} of {{ $order->customer->first_name }}’s with you, not linked to an order — <a href="{{ route('customers.show', $order->customer) }}#materials">see all</a>.</p>@endif
</x-cb.card>
@endcan
