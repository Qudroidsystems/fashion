@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php
    use App\Models\CustomerMaterial;
    $canManage = auth()->user()->can('Manage customer materials');
    $tabs = ['with_house' => 'With us', 'overdue' => 'Should go back', 'returned' => 'Returned / closed', 'all' => 'All'];
@endphp

    <x-cb.hero title="Customer materials" icon="ri-archive-drawer-line"
               subtitle="Fabric, lace and garments customers leave with you — tagged, photographed and followed until they go back.">
        @if($canManage)
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('materials.create') }}"><i class="ri-add-line me-1"></i>Receive an item</a>
        </x-slot:actions>
        @endif
    </x-cb.hero>
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="With us now" :value="$counts['with_house']" icon="ri-archive-drawer-line" accent="teal" :hint="$counts['customers'] . ' ' . \Illuminate\Support\Str::plural('customer', $counts['customers'])" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Should go back" :value="$counts['overdue']" icon="ri-alarm-warning-line" accent="rose" hint="Order handed over, collect-by date passed or kept too long" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Returned this month" :value="$counts['returned_month']" icon="ri-checkbox-circle-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Reminders" :value="$settings['reminders'] ? 'On' : 'Off'" icon="ri-notification-3-line" accent="amber"
            :hint="$settings['reminders'] ? collect($settings['channels'])->map(fn ($c) => \App\Models\MessageLog::CHANNELS[$c] . (($channels[$c] ?? false) ? '' : ' (not set up)'))->implode(', ') : 'Customers are not reminded automatically'" /></div>
    </div>

    <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="btn-group btn-group-sm" role="group">
            @foreach($tabs as $k => $label)
                <a href="{{ route('materials.index', array_filter(['show' => $k, 'q' => $q])) }}" class="btn {{ $show === $k ? 'btn-primary' : 'btn-light' }}">{{ $label }}@if($k === 'overdue' && $counts['overdue']) <span class="badge bg-danger ms-1">{{ $counts['overdue'] }}</span>@endif</a>
            @endforeach
        </div>
        <input type="hidden" name="show" value="{{ $show }}">
        <input name="q" value="{{ $q }}" class="form-control form-control-sm" style="max-width:260px" placeholder="Tag no., customer, phone, item, shelf">
        @if($branches->count() > 1)
            <select name="branch_id" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()"><option value="">All branches</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select>
        @endif
        <button class="btn btn-light btn-sm"><i class="ri-search-line"></i></button>
    </form>

    <x-cb.card title="Items" icon="ri-list-check-2" :count="$rows->total()" :flush="true">
        @if($rows->isEmpty())
            <div class="p-4 text-center text-muted">
                {{ $show === 'overdue' ? 'Nothing is waiting to go back.' : 'No items here yet.' }}
                @if($canManage && $show === 'with_house')<div class="mt-2"><a href="{{ route('materials.create') }}">Receive the first item</a></div>@endif
            </div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Tag</th><th>Item</th><th>Customer</th><th>Order</th><th>Where</th><th>Received</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($rows as $m)
                <tr onclick="location.href='{{ route('materials.show', $m) }}'" style="cursor:pointer">
                    <td data-label="Tag"><a href="{{ route('materials.show', $m) }}" class="fw-semibold text-nowrap">{{ $m->number }}</a></td>
                    <td data-label="Item">
                        <div class="d-flex align-items-center gap-2">
                            @if($m->photos)<img src="{{ $m->photoUrls()[0] }}" alt="" width="36" height="36" class="rounded object-fit-cover">@else<span class="avatar-xs rounded bg-light d-inline-grid" style="width:36px;height:36px;place-items:center"><i class="ri-t-shirt-line text-muted"></i></span>@endif
                            <div><div>{{ $m->summary }}</div><div class="small text-muted">{{ $m->kind_label }} · {{ CustomerMaterial::PURPOSES[$m->purpose] ?? $m->purpose }}</div></div>
                        </div>
                    </td>
                    <td data-label="Customer">{{ $m->customer?->name }}<div class="small text-muted">{{ $m->customer?->phone }}</div></td>
                    <td data-label="Order">@if($m->order)<a href="{{ route('orders.show', $m->order) }}" onclick="event.stopPropagation()">{{ $m->order->number }}</a><div class="small text-muted">{{ \App\Support\Orders\OrderStatus::label($m->order->status) }}</div>@else<span class="text-muted">—</span>@endif</td>
                    <td data-label="Where">{{ $m->location ?: '—' }}@if($m->branch && $branches->count() > 1)<div class="small text-muted">{{ $m->branch->name }}</div>@endif</td>
                    <td data-label="Received" class="text-nowrap">{{ $m->received_at->timezone($tz)->format('j M Y') }}<div class="small text-muted">{{ $m->daysHeld() }} {{ \Illuminate\Support\Str::plural('day', $m->daysHeld()) }}</div></td>
                    <td data-label="Status">
                        <span class="status-pill {{ CustomerMaterial::STATUSES[$m->status][1] ?? 'st-muted' }}">{{ $m->status_label }}</span>
                        @if(isset($overdueIds[$m->id]))<span class="status-pill st-danger ms-1">Should go back</span>@endif
                        @if($m->reminders_sent)<div class="small text-muted mt-1"><i class="ri-notification-3-line"></i> {{ $m->reminders_sent }}× reminded</div>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="p-3">{{ $rows->links() }}</div>
        @endif
    </x-cb.card>

    @if($canManage)
    <x-cb.card title="Reminders" icon="ri-notification-3-line" class="mt-3">
        <form method="post" action="{{ route('materials.settings') }}" class="row g-3">
            @csrf @method('put')
            <div class="col-lg-6">
                <label class="d-flex gap-2 mb-2"><input type="hidden" name="reminders" value="0"><input type="checkbox" name="reminders" value="1" @checked($settings['reminders'])> Remind customers to collect their items</label>
                <div class="d-flex flex-wrap gap-3 ms-4 mb-2">
                    @foreach(\App\Models\MessageLog::CHANNELS as $k => $label)
                        <label class="d-flex gap-1"><input type="checkbox" name="channels[]" value="{{ $k }}" @checked(in_array($k, $settings['channels'], true))> {{ $label }}
                            @unless($channels[$k] ?? false)<span class="small text-warning">(not set up)</span>@endunless</label>
                    @endforeach
                </div>
                <label class="d-flex gap-2"><input type="hidden" name="staff_digest" value="0"><input type="checkbox" name="staff_digest" value="1" @checked($settings['staff_digest'])> Every morning, tell staff who look after materials what should have gone back</label>
                <div class="form-text mt-2">SMS and WhatsApp need your own account — set them up in <a href="{{ route('settings.messaging') }}">Messaging settings</a>. Customers who said no to a channel are not messaged on it. Reminders go between 9am and 7pm.</div>
            </div>
            <div class="col-lg-6">
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">Items without an order: remind after (days)</label><input type="number" name="unlinked_days" min="1" max="365" class="form-control" value="{{ $settings['unlinked_days'] }}"></div>
                    <div class="col-6"><label class="form-label small">First reminder, days after it’s due</label><input type="number" name="remind_after_days" min="0" max="90" class="form-control" value="{{ $settings['remind_after_days'] }}"></div>
                    <div class="col-6"><label class="form-label small">Then remind every (days)</label><input type="number" name="repeat_days" min="1" max="90" class="form-control" value="{{ $settings['repeat_days'] }}"></div>
                    <div class="col-6"><label class="form-label small">Stop after (reminders)</label><input type="number" name="max_reminders" min="0" max="20" class="form-control" value="{{ $settings['max_reminders'] }}"></div>
                </div>
                <div class="form-text mt-2">An item is due to go back on its collect-by date; if it belongs to an order, once that order is handed over or cancelled; otherwise after the days above.</div>
                <button class="btn btn-primary mt-2">Save</button>
            </div>
        </form>
    </x-cb.card>
    @endif

</div>
</div>
</div>
@endsection
