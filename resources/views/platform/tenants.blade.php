@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Fashion Houses" icon="ri-community-line"
               subtitle="Platform operator view: onboard fashion houses and manage their subscription status.">
        <x-slot:pills>
            @foreach($statuses as $s)
                <span class="cb-meta-pill">{{ ucfirst(str_replace('_', ' ', $s)) }}: {{ $counts[$s] ?? 0 }}</span>
            @endforeach
        </x-slot:pills>
        <x-slot:actions>
            <button class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#newTenantModal"><i class="ri-add-line me-1"></i>New fashion house</button>
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))
        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
    @endif
    @if($errors->any())
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
    @endif

    <x-cb.card title="All fashion houses" icon="ri-store-3-line" :count="$tenants->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input name="q" value="{{ request('q') }}" placeholder="Name or slug…"></div>
            <select name="status" class="cb-select" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
            </select>
        </form>
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Fashion house</th><th>Staff</th><th>Branches</th><th>Currency</th><th>Created</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($tenants as $t)
                    @php $pill = ['trial' => 'st-info', 'active' => 'st-success', 'past_due' => 'st-warning', 'suspended' => 'st-danger', 'cancelled' => 'st-muted'][$t->status] ?? 'st-muted'; @endphp
                    <tr>
                        <td data-label="Fashion house">
                            <div class="fw-semibold">{{ $t->name }}</div>
                            <div class="small text-muted">{{ $t->slug }}@if($t->domain) · {{ $t->domain }}@endif</div>
                        </td>
                        <td data-label="Staff">{{ $t->staff_count }}</td>
                        <td data-label="Branches">{{ $t->branches_count }}</td>
                        <td data-label="Currency">{{ $t->currency_code }}</td>
                        <td data-label="Created">{{ $t->created_at->format('d M Y') }}</td>
                        <td data-label="Status">
                            <form method="POST" action="{{ route('platform.tenants.status', $t) }}" class="d-flex gap-2 align-items-center">
                                @csrf @method('PATCH')
                                <span class="status-pill {{ $pill }}">{{ ucfirst(str_replace('_', ' ', $t->status)) }}</span>
                                <select name="status" class="cb-select" onchange="if(confirm('Change status of {{ addslashes($t->name) }}?')) this.form.submit(); else this.value='{{ $t->status }}';">
                                    @foreach($statuses as $s)<option value="{{ $s }}" @selected($t->status === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
                                </select>
                            </form>
                            @if($t->status === 'trial' && $t->trial_ends_at)
                                <div class="small text-muted mt-1">Trial ends {{ $t->trial_ends_at->format('d M Y') }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="ri-store-3-line"></i><h6>No fashion houses yet</h6><p>Create the first one.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($tenants->hasPages())<div class="px-4 py-3">{{ $tenants->links() }}</div>@endif
    </x-cb.card>

    <div class="modal fade" id="newTenantModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('platform.tenants.store') }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title">New fashion house</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Business name *</label><input name="name" class="form-control" required value="{{ old('name') }}"></div>
                    <div class="mb-3"><label class="form-label">Slug <span class="text-muted">(optional)</span></label><input name="slug" class="form-control" value="{{ old('slug') }}" placeholder="auto from name"></div>
                    <div class="row g-3 mb-3">
                        <div class="col-6"><label class="form-label">Status</label>
                            <select name="status" class="form-select"><option value="trial">Trial</option><option value="active">Active</option></select></div>
                        <div class="col-6"><label class="form-label">Currency</label><input name="currency_code" class="form-control text-uppercase" maxlength="3" value="{{ old('currency_code', config('tenancy.defaults.currency')) }}"></div>
                    </div>
                    <hr>
                    <div class="mb-3"><label class="form-label">Owner name *</label><input name="owner_name" class="form-control" required value="{{ old('owner_name') }}"></div>
                    <div class="mb-1"><label class="form-label">Owner e-mail *</label><input type="email" name="owner_email" class="form-control" required value="{{ old('owner_email') }}"></div>
                    <div class="form-text">A temporary password is generated for a new owner and shown once after saving.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create</button></div>
            </form>
        </div>
    </div>

</div>
</div>
</div>
@endsection
