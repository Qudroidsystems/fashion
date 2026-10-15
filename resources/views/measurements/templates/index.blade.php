@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Measurement templates" icon="ri-file-list-2-line"
               subtitle="Which measurements each garment needs. Publishing creates a new version; old sessions keep theirs.">
        <x-slot:actions>
            <a href="{{ route('measurement-parameters.index') }}" class="cb-hero-btn"><i class="ri-ruler-2-line me-1"></i>Parameters</a>
            <button class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#newTemplate"><i class="ri-add-line me-1"></i>New template</button>
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <div class="row g-3">
        @forelse($templates as $t)
            <div class="col-md-6 col-xl-4">
                <x-cb.card :title="$t->name" icon="ri-t-shirt-line">
                    <div class="small text-muted mb-2">{{ $t->garment_category ?? 'General' }} · {{ $audiences[$t->audience] }} · <code>{{ $t->code }}</code></div>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        @if($t->currentVersion)
                            <span class="status-pill st-success">v{{ $t->currentVersion->version_no }} live · {{ $t->currentVersion->parameters_count }} measurements</span>
                        @else
                            <span class="status-pill st-muted">Not published</span>
                        @endif
                        @if($t->draft)<span class="status-pill st-warning">Draft v{{ $t->draft->version_no }}</span>@endif
                        @unless($t->is_active)<span class="status-pill st-muted">Inactive</span>@endunless
                    </div>
                    <a href="{{ route('measurement-templates.show', $t) }}" class="action-btn btn-primary-cb">Open</a>
                </x-cb.card>
            </div>
        @empty
            <div class="col-12"><div class="empty-state"><i class="ri-file-list-2-line"></i><h6>No templates</h6><p>Create one, or run <code>php artisan fhmp:measurement-kit</code> for the starter set.</p></div></div>
        @endforelse
    </div>

    <div class="modal fade" id="newTemplate" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" method="POST" action="{{ route('measurement-templates.store') }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title">New template</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Name *</label><input name="name" class="form-control" required placeholder="e.g. Kaftan"></div>
                        <div class="col-md-6"><label class="form-label">Code *</label><input name="code" class="form-control font-monospace" required placeholder="kaftan"></div>
                        <div class="col-md-6"><label class="form-label">Category</label><input name="garment_category" class="form-control" placeholder="Native"></div>
                        <div class="col-md-6"><label class="form-label">For</label><select name="audience" class="form-select">@foreach($audiences as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="col-12">
                            <label class="form-label">Measurements</label>
                            <div class="row g-1">
                                @foreach($parameters as $p)
                                    <div class="col-md-4"><label class="small d-flex gap-2 align-items-center"><input type="checkbox" name="parameters[]" value="{{ $p->id }}"> {{ $p->label }}</label></div>
                                @endforeach
                            </div>
                            <div class="form-text">You can reorder and mark optional ones on the next screen.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create draft</button></div>
            </form>
        </div>
    </div>

</div>
</div>
</div>
@endsection
