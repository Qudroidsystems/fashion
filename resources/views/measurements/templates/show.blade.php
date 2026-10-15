@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        $unit = $currentTenant->setting('measurement_display_unit', 'in');
        $inDraft = $draft ? $draft->parameters->keyBy('parameter_id') : collect();
    @endphp

    <x-cb.hero :title="$template->name" icon="ri-t-shirt-line"
               :subtitle="($template->garment_category ?? 'General') . ' · ' . $audiences[$template->audience]"
               :back="route('measurement-templates.index')" back-label="Templates">
        <x-slot:pills>
            @if($current)<span class="cb-meta-pill">Live: v{{ $current->version_no }}</span>@else<span class="cb-meta-pill">Not published yet</span>@endif
            @if($draft)<span class="cb-meta-pill">Editing draft v{{ $draft->version_no }}</span>@endif
        </x-slot:pills>
        @unless($draft)
        <x-slot:actions>
            <form method="POST" action="{{ route('measurement-templates.draft', $template) }}">@csrf
                <button class="cb-hero-btn"><i class="ri-edit-2-line me-1"></i>Edit measurements (new version)</button>
            </form>
        </x-slot:actions>
        @endunless
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            @if($draft)
            <form method="POST" action="{{ route('measurement-templates.save-draft', $template) }}">
                @csrf @method('PUT')
                <x-cb.card :title="'Draft v' . $draft->version_no" icon="ri-draft-line" :flush="true">
                    <x-slot:tools><span class="small text-muted">Tick to include · number sets the order</span></x-slot:tools>
                    <div class="table-responsive">
                        <table class="cb-table">
                            <thead><tr><th>Include</th><th>Order</th><th>Measurement</th><th>Required</th><th>Note for this garment</th></tr></thead>
                            <tbody>
                            @php
                                $ordered = $parameters->sortBy(fn ($p) => $inDraft->has($p->id) ? $inDraft[$p->id]->sort_order : 1000 + $p->sort_order)->values();
                            @endphp
                            @foreach($ordered as $i => $p)
                                @php $item = $inDraft->get($p->id); @endphp
                                <tr>
                                    <td>
                                        <input type="hidden" name="items[{{ $i }}][parameter_id]" value="{{ $p->id }}">
                                        <input type="checkbox" class="form-check-input" name="items[{{ $i }}][include]" value="1" @checked($item)>
                                    </td>
                                    <td style="width:80px"><input type="number" class="form-control form-control-sm" name="items[{{ $i }}][order]" value="{{ $item ? $item->sort_order + 1 : '' }}" min="1"></td>
                                    <td><span class="fw-semibold">{{ $p->label }}</span> <code class="small">{{ $p->code }}</code>
                                        @if($p->is_bilateral)<span class="status-pill st-info ms-1">L/R</span>@endif</td>
                                    <td><input type="checkbox" class="form-check-input" name="items[{{ $i }}][is_required]" value="1" @checked($item ? $item->is_required : true)></td>
                                    <td><input class="form-control form-control-sm" name="items[{{ $i }}][guidance]" value="{{ $item?->guidance }}" maxlength="255" placeholder="optional"></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center p-3 border-top">
                        <input name="change_notes" class="form-control form-control-sm" style="max-width:360px" placeholder="What changed? (shown in version history)">
                        <button name="action" value="save" class="action-btn ms-auto"><i class="ri-save-line"></i>Save draft</button>
                        <button name="action" value="publish" class="action-btn btn-primary-cb" onclick="return confirm('Publish v{{ $draft->version_no }}? New sessions will use it; existing sessions keep their version.')"><i class="ri-send-plane-line"></i>Publish v{{ $draft->version_no }}</button>
                    </div>
                </x-cb.card>
            </form>
            @elseif($current)
            <x-cb.card :title="'Live version v' . $current->version_no" icon="ri-checkbox-circle-line">
                <ol class="mb-0">
                    @foreach($current->parameters as $tp)
                        <li class="mb-1"><span class="fw-semibold">{{ $tp->label }}</span>
                            @unless($tp->is_required)<span class="status-pill st-muted ms-1">optional</span>@endunless
                            @if($tp->is_bilateral)<span class="status-pill st-info ms-1">L/R</span>@endif
                            @if($range = $tp->rangeText($unit))<span class="small text-muted ms-1">{{ $range }}</span>@endif
                            @if($tp->guidance)<div class="small text-muted">{{ $tp->guidance }}</div>@endif
                        </li>
                    @endforeach
                </ol>
            </x-cb.card>
            @endif
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Details" icon="ri-information-line">
                <form method="POST" action="{{ route('measurement-templates.update', $template) }}">
                    @csrf @method('PUT')
                    <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" value="{{ $template->name }}" required></div>
                    <div class="mb-2"><label class="form-label">Category</label><input name="garment_category" class="form-control" value="{{ $template->garment_category }}"></div>
                    <div class="mb-2"><label class="form-label">For</label><select name="audience" class="form-select">@foreach($audiences as $k => $v)<option value="{{ $k }}" @selected($template->audience === $k)>{{ $v }}</option>@endforeach</select></div>
                    <div class="mb-2"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ $template->description }}</textarea></div>
                    <div class="form-check form-switch mb-3"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="t-active" @checked($template->is_active)><label class="form-check-label" for="t-active">Available for new sessions</label></div>
                    <button class="action-btn btn-primary-cb">Save details</button>
                </form>
            </x-cb.card>

            <x-cb.card title="Version history" icon="ri-history-line" class="mt-3" :flush="true">
                @foreach($versions as $v)
                    <div class="px-4 py-2 border-bottom small">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold">v{{ $v->version_no }}</span>
                            <span class="status-pill {{ ['draft' => 'st-warning', 'published' => 'st-success', 'retired' => 'st-muted'][$v->status] }}">{{ $v->status === 'retired' ? 'older' : $v->status }}</span>
                        </div>
                        <div class="text-muted">{{ $v->parameters_count }} measurements · used by {{ $usage[$v->id] ?? 0 }} session(s)
                            @if($v->published_at) · {{ $v->published_at->timezone($currentTenant->timezone)->format('d M Y') }}@endif</div>
                        @if($v->change_notes)<div>{{ $v->change_notes }}</div>@endif
                    </div>
                @endforeach
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
