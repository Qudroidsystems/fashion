@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Customers" icon="ri-user-heart-line" subtitle="Everyone who buys from or is measured by {{ $brand['name'] }}.">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ number_format($stats['total']) }} active</span>
            <span class="cb-meta-pill">{{ $stats['vip'] }} VIP</span>
            <span class="cb-meta-pill">{{ $stats['new'] }} new this month</span>
        </x-slot:pills>
        <x-slot:actions>
            <a href="{{ route('follow-ups.index') }}" class="cb-hero-btn"><i class="ri-alarm-line me-1"></i>Follow-ups @if($stats['overdue'])<span class="badge bg-danger ms-1">{{ $stats['overdue'] }}</span>@endif</a>
            @can('Export customer')
                <a href="{{ route('customers.export', request()->query()) }}" class="cb-hero-btn"><i class="ri-download-2-line me-1"></i>Export</a>
            @endcan
            @can('Create customer')
                <a href="{{ route('customers.create') }}" class="cb-hero-btn"><i class="ri-user-add-line me-1"></i>New customer</a>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))
        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><x-cb.stat label="Active customers" :value="number_format($stats['total'])" icon="ri-user-heart-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="VIP" :value="$stats['vip']" icon="ri-vip-crown-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Open follow-ups" :value="$stats['followups']" icon="ri-alarm-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Overdue follow-ups" :value="$stats['overdue']" icon="ri-alarm-warning-line" accent="rose" /></div>
    </div>

    <x-cb.card title="Customer list" icon="ri-contacts-book-line" :count="$customers->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, phone, e-mail or number…" autofocus>
            </div>
            <select name="tag" class="cb-select" onchange="this.form.submit()">
                <option value="">All tags</option>
                @foreach($tags as $t)<option value="{{ $t->id }}" @selected(($filters['tag'] ?? '') == $t->id)>{{ $t->name }}</option>@endforeach
            </select>
            <select name="vip" class="cb-select" onchange="this.form.submit()">
                <option value="">VIP: any</option>
                <option value="1" @selected(($filters['vip'] ?? '') === '1')>VIP only</option>
                <option value="0" @selected(($filters['vip'] ?? '') === '0')>Not VIP</option>
            </select>
            @if($branches->count() > 1)
            <select name="branch" class="cb-select" onchange="this.form.submit()">
                <option value="">All branches</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected(($filters['branch'] ?? '') == $b->id)>{{ $b->name }}</option>@endforeach
            </select>
            @endif
            <select name="follow_up" class="cb-select" onchange="this.form.submit()">
                <option value="">Follow-up: any</option>
                <option value="open" @selected(($filters['follow_up'] ?? '') === 'open')>Has open follow-up</option>
                <option value="overdue" @selected(($filters['follow_up'] ?? '') === 'overdue')>Overdue follow-up</option>
                <option value="none" @selected(($filters['follow_up'] ?? '') === 'none')>No follow-up</option>
            </select>
            <select name="status" class="cb-select" onchange="this.form.submit()">
                <option value="active" @selected(($filters['status'] ?? 'active') === 'active')>Active</option>
                <option value="archived" @selected(($filters['status'] ?? '') === 'archived')>Archived</option>
                <option value="merged" @selected(($filters['status'] ?? '') === 'merged')>Merged</option>
            </select>
            <label class="d-flex align-items-center gap-1 small mb-0"><input type="checkbox" name="mine" value="1" @checked(!empty($filters['mine'])) onchange="this.form.submit()"> Assigned to me</label>
            <label class="d-flex align-items-center gap-1 small mb-0"><input type="checkbox" name="birthday" value="month" @checked(($filters['birthday'] ?? '') === 'month') onchange="this.form.submit()"> Birthday this month</label>
            <select name="sort" class="cb-select ms-auto" onchange="this.form.submit()">
                <option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>Newest first</option>
                <option value="name" @selected(($filters['sort'] ?? '') === 'name')>Name A–Z</option>
                <option value="number" @selected(($filters['sort'] ?? '') === 'number')>Customer no.</option>
                <option value="activity" @selected(($filters['sort'] ?? '') === 'activity')>Recent activity</option>
            </select>
            <button class="action-btn btn-primary-cb"><i class="ri-filter-3-line"></i>Apply</button>
            @if(collect($filters)->except('sort')->filter(fn ($v) => filled($v) && $v !== 'active')->isNotEmpty())
                <a href="{{ route('customers.index') }}" class="action-btn">Clear</a>
            @endif
        </form>

        <div class="table-responsive">
            <table class="cb-table stack">
                <thead>
                    <tr><th>Customer</th><th>Contact</th><th>Tags</th><th>Branch</th><th>Follow-ups</th><th>Added</th><th></th></tr>
                </thead>
                <tbody>
                @forelse($customers as $c)
                    <tr>
                        <td data-label="Customer">
                            <a href="{{ route('customers.show', $c) }}" class="d-flex align-items-center gap-2 text-decoration-none" style="color:inherit">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                                      style="width:36px;height:36px;background:var(--cb-surface-2);color:var(--cb-teal);font-weight:700;font-size:13px;">{{ $c->initials }}</span>
                                <span>
                                    <span class="fw-semibold d-block">{{ $c->full_name }}
                                        @if($c->is_vip)<i class="ri-vip-crown-fill text-warning" title="VIP"></i>@endif
                                    </span>
                                    <span class="small text-muted">{{ $c->customer_number }}</span>
                                </span>
                            </a>
                        </td>
                        <td data-label="Contact" class="small">{{ $c->phone }}@if($c->email)<br><span class="text-muted">{{ $c->email }}</span>@endif</td>
                        <td data-label="Tags">
                            @foreach($c->tags as $t)
                                <span class="status-pill me-1" style="background:color-mix(in srgb, {{ $t->color }} 14%, #fff);color:{{ $t->color }}">{{ $t->name }}</span>
                            @endforeach
                        </td>
                        <td data-label="Branch" class="small">{{ $c->branch?->name ?? '—' }}</td>
                        <td data-label="Follow-ups">
                            @if($c->open_follow_ups)<span class="status-pill st-warning">{{ $c->open_follow_ups }} open</span>@else<span class="text-muted small">—</span>@endif
                        </td>
                        <td data-label="Added" class="small text-muted text-nowrap">{{ $c->created_at->format('d M Y') }}</td>
                        <td class="text-end"><a href="{{ route('customers.show', $c) }}" class="action-btn btn-primary-cb">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <i class="ri-user-search-line"></i>
                            <h6>{{ ! empty($filters['q']) ? 'No customer matches “' . $filters['q'] . '”' : 'No customers yet' }}</h6>
                            <p>@can('Create customer')<a href="{{ route('customers.create') }}">Add a customer</a> to get started.@endcan</p>
                        </div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($customers->hasPages())<div class="px-4 py-3">{{ $customers->links() }}</div>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
