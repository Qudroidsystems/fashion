@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Models\ProductionStage; use App\Models\ProductionWorkflow; @endphp
    <x-cb.hero title="Production setup" icon="ri-settings-4-line" subtitle="Workflows decide which stages each kind of garment goes through. Changes apply to garments planned from now on.">
        <x-slot:actions><a href="{{ route('production.workflows.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New workflow</a></x-slot:actions>
    </x-cb.hero>
    @include('production.partials-nav')
    @include('inventory.partials.flash')

    <div class="row g-3">
        <div class="col-xl-8">
            @foreach($workflows as $w)
                <x-cb.card :title="$w->name" icon="ri-route-line" class="{{ $loop->first ? '' : 'mt-3' }} {{ $w->is_active ? '' : 'opacity-50' }}">
                    <x-slot:tools><a href="{{ route('production.workflows.edit', $w) }}" class="action-btn"><i class="ri-edit-line"></i>Edit</a></x-slot:tools>
                    <div class="small text-muted mb-2">
                        For {{ collect($w->line_kinds)->map(fn ($k) => strtolower(ProductionWorkflow::LINE_KINDS[$k] ?? $k))->implode(', ') }}{{ $w->category ? ' in ' . $w->category->name : '' }}
                        @if($w->is_default) · <span class="status-pill st-info">Default</span>@endif
                        @unless($w->is_active) · <span class="status-pill st-muted">Off</span>@endunless
                        · about {{ round($w->totalHours() / 8, 1) }} working day(s)
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        @foreach($w->stages as $s)
                            <span class="status-pill {{ ['work' => 'st-muted', 'fitting' => 'st-warning', 'qc' => 'st-violet'][$s->type] }}" title="{{ ProductionStage::TYPES[$s->type] }} · {{ $s->duration_hours }}h{{ $s->role ? ' · ' . $s->role->name : '' }}{{ $s->customer_visible ? ' · customer sees it' : '' }}">
                                {{ $s->name }} <span class="opacity-75">{{ $s->duration_hours }}h</span></span>
                            @unless($loop->last)<i class="ri-arrow-right-s-line text-muted"></i>@endunless
                        @endforeach
                    </div>
                    @if($w->description)<p class="small mt-2 mb-0">{{ $w->description }}</p>@endif
                </x-cb.card>
            @endforeach
            @if($workflows->isEmpty())<div class="empty-state"><i class="ri-route-line"></i><h6>No workflows yet</h6></div>@endif
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Quality checklists" icon="ri-shield-check-line">
                @foreach($checklists as $c)
                    <details class="border-bottom py-2">
                        <summary class="fw-semibold">{{ $c->name }} <span class="small text-muted">· {{ count($c->items) }} items{{ $c->is_active ? '' : ' · off' }}</span></summary>
                        <form method="POST" action="{{ route('production.checklists.update', $c) }}" class="mt-2">@csrf @method('PUT')
                            <input name="name" class="form-control form-control-sm mb-2" value="{{ $c->name }}" required maxlength="120">
                            <textarea name="items" class="form-control form-control-sm mb-2" rows="6" required>{{ collect($c->items)->map(fn ($i) => (($i['required'] ?? true) ? '' : '? ') . $i['label'])->implode("\n") }}</textarea>
                            <label class="small d-flex gap-1 mb-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> In use</label>
                            <button class="action-btn w-100 justify-content-center">Save checklist</button>
                        </form>
                    </details>
                @endforeach
                <details class="py-2">
                    <summary class="fw-semibold text-primary">New checklist</summary>
                    <form method="POST" action="{{ route('production.checklists.store') }}" class="mt-2">@csrf
                        <input name="name" class="form-control form-control-sm mb-2" required maxlength="120" placeholder="e.g. Agbada final check">
                        <textarea name="items" class="form-control form-control-sm mb-2" rows="5" required placeholder="One item per line&#10;? Start with ? for optional items"></textarea>
                        <button class="action-btn w-100 justify-content-center">Create checklist</button>
                    </form>
                </details>
                <p class="small text-muted mt-2 mb-0">One item per line. Start a line with “?” to make it optional — failing a required item creates rework.</p>
            </x-cb.card>
        </div>
    </div>
</div>
</div>
</div>
@endsection
