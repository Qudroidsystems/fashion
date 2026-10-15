@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Branches & Locations" icon="ri-building-4-line"
               subtitle="Shops, production rooms, warehouses and POS points. Staff, stock and sales are tracked per location."
               :back="route('dashboard')" back-label="Dashboard">
        @can('Manage branch')
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#branchModal" data-mode="create">
                <i class="ri-add-line me-1"></i>Add location
            </button>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    @if(session('success'))
        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
    @endif
    @if(session('error'))
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>
    @endif
    @if($errors->any())
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i>
            <div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        </div>
    @endif

    <x-cb.card title="Locations" icon="ri-map-pin-2-line" :count="$branches->count()" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead>
                    <tr><th>Name</th><th>Code</th><th>Type</th><th>Contact</th><th>Staff</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($branches as $b)
                    @php $payload = $b->only(['id', 'name', 'code', 'type', 'phone', 'email', 'address', 'city', 'state', 'is_main', 'is_active']); @endphp
                    <tr>
                        <td data-label="Name">
                            <span class="fw-semibold">{{ $b->name }}</span>
                            @if($b->is_main)<span class="status-pill st-info ms-1">Main</span>@endif
                            @if($b->address)<div class="small text-muted">{{ $b->address }}{{ $b->city ? ', ' . $b->city : '' }}</div>@endif
                        </td>
                        <td data-label="Code"><code>{{ $b->code }}</code></td>
                        <td data-label="Type">{{ $b->type_label }}</td>
                        <td data-label="Contact" class="small">{{ $b->phone ?? '—' }}<br>{{ $b->email }}</td>
                        <td data-label="Staff">{{ $b->memberships_count }}</td>
                        <td data-label="Status"><span class="status-pill {{ $b->is_active ? 'st-success' : 'st-muted' }}">{{ $b->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="text-end text-nowrap">
                            @can('Manage branch')
                            <button type="button" class="action-btn btn-primary-cb" data-bs-toggle="modal" data-bs-target="#branchModal"
                                    data-mode="edit" data-branch="{{ json_encode($payload) }}">
                                <i class="ri-pencil-line"></i>Edit
                            </button>
                            @unless($b->is_main)
                            <form method="POST" action="{{ route('settings.branches.destroy', $b) }}" class="d-inline"
                                  onsubmit="return confirm('Remove {{ addslashes($b->name) }}? Staff assigned to it will have no branch.')">
                                @csrf @method('DELETE')
                                <button class="action-btn btn-danger-soft"><i class="ri-delete-bin-line"></i></button>
                            </form>
                            @endunless
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-cb.card>

    @can('Manage branch')
    <div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form class="modal-content" method="POST" id="branchForm" action="{{ route('settings.branches.store') }}">
                @csrf
                <input type="hidden" name="_method" value="POST" id="branchMethod">
                <div class="modal-header">
                    <h5 class="modal-title" id="branchModalTitle">Add location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Name *</label><input name="name" id="b-name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Code *</label><input name="code" id="b-code" class="form-control text-uppercase" maxlength="20" required placeholder="e.g. LEKKI"></div>
                        <div class="col-md-6">
                            <label class="form-label">Type *</label>
                            <select name="type" id="b-type" class="form-select">
                                @foreach($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Phone</label><input name="phone" id="b-phone" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" id="b-email" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Address</label><input name="address" id="b-address" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">City</label><input name="city" id="b-city" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">State</label><input name="state" id="b-state" class="form-control"></div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="b-active" checked><label class="form-check-label" for="b-active">Active</label></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_main" value="1" id="b-main"><label class="form-check-label" for="b-main">Main branch</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="ri-save-line me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
    @endcan

</div>
</div>
</div>

<script>
document.getElementById('branchModal')?.addEventListener('show.bs.modal', function (e) {
    const trigger = e.relatedTarget;
    const form = document.getElementById('branchForm');
    const b = trigger?.dataset.branch ? JSON.parse(trigger.dataset.branch) : null;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    form.reset();
    document.getElementById('branchModalTitle').textContent = b ? 'Edit ' + b.name : 'Add location';
    form.action = b ? @json(url('/settings/branches')) + '/' + b.id : @json(route('settings.branches.store'));
    document.getElementById('branchMethod').value = b ? 'PUT' : 'POST';
    ['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'state'].forEach(k => set('b-' + k, b ? b[k] : (k === 'type' ? 'branch' : '')));
    document.getElementById('b-active').checked = b ? !!b.is_active : true;
    document.getElementById('b-main').checked = b ? !!b.is_main : false;
});
</script>
@endsection
