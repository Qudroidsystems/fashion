@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Money;
        $u = auth()->user();
        $images = $version?->images ?? collect();
        $isLive = $version && $version->id === $style->current_version_id;
    @endphp

    <x-cb.hero :title="$style->name" icon="ri-t-shirt-air-line"
               :subtitle="$style->code . ($style->category ? ' · ' . $style->category->name : '') . ' · ' . \App\Models\FashionStyle::AUDIENCES[$style->audience]"
               :back="route('styles.index')" back-label="Styles">
        <x-slot:pills>
            @if($version)
                <span class="status-pill {{ ['draft' => 'st-warning', 'published' => 'st-success', 'retired' => 'st-muted'][$version->status] }}">
                    v{{ $version->version_no }} · {{ $version->status === 'published' ? 'live' : $version->status }}</span>
            @endif
            @if($style->status === 'archived')<span class="cb-meta-pill">Archived</span>@endif
            @if($style->show_in_catalogue)<span class="cb-meta-pill"><i class="ri-eye-line me-1"></i>In customer catalogue</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @can('Manage style')
                @if($style->status === 'active')
                    @if($draft)
                        <a href="{{ route('styles.edit', $style) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Continue draft v{{ $draft->version_no }}</a>
                    @else
                        <form method="POST" action="{{ route('styles.new-draft', $style) }}" class="d-inline">@csrf
                            <button class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit (new version)</button></form>
                    @endif
                @endif
                <form method="POST" action="{{ route('styles.archive', $style) }}" class="d-inline">@csrf
                    <button class="cb-hero-btn">{!! $style->status === 'archived' ? '<i class="ri-inbox-unarchive-line me-1"></i>Restore' : '<i class="ri-archive-line me-1"></i>Archive' !!}</button>
                </form>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
    @if($version && $version->status === 'retired')
        <div class="cb-banner info"><i class="ri-history-line"></i><div>You are looking at an older version. Orders placed with it keep these details. <a href="{{ route('styles.show', $style) }}">See the live version</a>.</div></div>
    @endif
    @if($draft && $version?->id !== $draft->id)
        <div class="cb-banner warning"><i class="ri-draft-line"></i><div class="flex-grow-1">Draft v{{ $draft->version_no }} has unpublished changes.
            <a href="{{ route('styles.show', [$style, 'version' => $draft->version_no]) }}">Preview</a> ·
            <a href="{{ route('styles.edit', $style) }}">Edit</a></div></div>
    @endif

    @if(! $version)
        <div class="empty-state"><i class="ri-t-shirt-air-line"></i><h6>No version</h6></div>
    @else
    <div class="row g-3">
        <div class="col-xl-5">
            <x-cb.card :flush="true">
                <div style="aspect-ratio:4/5;background:#f3f5f9" class="d-grid" id="mainPic">
                    @if($images->isNotEmpty())
                        <img src="{{ $images->first()->url }}" alt="{{ $style->name }}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit" id="mainImg">
                    @else
                        <i class="ri-image-line m-auto" style="font-size:56px;color:#c3c8d4"></i>
                    @endif
                </div>
                @if($images->count() > 1)
                    <div class="d-flex gap-2 p-2 overflow-auto">
                        @foreach($images as $img)
                            <img src="{{ $img->url }}" alt="" class="rounded-2 thumb" style="width:64px;height:80px;object-fit:cover;cursor:pointer" onclick="document.getElementById('mainImg').src=this.src">
                        @endforeach
                    </div>
                @endif
            </x-cb.card>
        </div>

        <div class="col-xl-7">
            <x-cb.card title="Details" icon="ri-information-line">
                <div class="d-flex flex-wrap gap-4 mb-3">
                    <div><div class="small text-muted">Starting price</div><div class="fs-4 fw-semibold">{{ $version->base_price !== null ? Money::format($version->base_price) : 'On request' }}</div></div>
                    <div><div class="small text-muted">Lead time</div><div class="fs-4 fw-semibold">{{ $version->lead_time_days ? $version->lead_time_days . ' days' : '—' }}</div></div>
                    @can('View costing')
                        <div><div class="small text-muted">Estimated cost</div><div class="fs-4 fw-semibold">{{ Money::format($version->estimated_cost) }}</div>
                            @if($version->estimated_cost && $version->base_price > 0)<div class="small text-muted">margin {{ round(($version->base_price - $version->estimated_cost) / $version->base_price * 100) }}%</div>@endif
                        </div>
                    @endcan
                </div>

                @if($version->description)<p style="white-space:pre-line">{{ $version->description }}</p>@endif

                @if($version->options())
                    <div class="fw-semibold small mb-1">Add-ons</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach($version->options() as $o)<span class="term-chip">{{ $o['label'] }} <strong>+{{ Money::format($o['amount']) }}</strong></span>@endforeach
                    </div>
                @endif

                @if($version->fabric_quantity || $version->fabric_notes)
                    <div class="fw-semibold small mb-1">Fabric</div>
                    <p class="small mb-3">{{ $version->fabric_quantity ? rtrim(rtrim((string) $version->fabric_quantity, '0'), '.') . ' ' . ($version->fabric_unit === 'm' ? 'm' : 'yd') : '' }}{{ $version->fabric_quantity && $version->fabric_notes ? ' — ' : '' }}{{ $version->fabric_notes }}</p>
                @endif

                <div class="fw-semibold small mb-1">Measurements needed</div>
                <div class="d-flex flex-wrap gap-2">
                    @forelse($version->templateLinks as $l)
                        <span class="term-chip"><i class="ri-ruler-2-line"></i> {{ $l->template?->name }}{{ $l->templateVersion ? ' v' . $l->templateVersion->version_no : '' }}</span>
                    @empty
                        <span class="small text-muted">None chosen — required before publishing.</span>
                    @endforelse
                </div>
            </x-cb.card>

            @if($version->technical_notes)
                <x-cb.card title="Technical notes" icon="ri-tools-line" class="mt-3">
                    <div style="white-space:pre-line" class="small">{{ $version->technical_notes }}</div>
                </x-cb.card>
            @endif

            @if($draft && $version->id === $draft->id && $u->can('Publish style'))
                <x-cb.card title="Publish this draft" icon="ri-rocket-line" class="mt-3">
                    <form method="POST" action="{{ route('styles.publish', $style) }}" class="d-flex gap-2 flex-wrap">
                        @csrf
                        <input name="change_notes" class="form-control" style="max-width:420px" maxlength="255" placeholder="What changed? (optional)">
                        <button class="action-btn btn-primary-cb"><i class="ri-rocket-line"></i>Publish v{{ $draft->version_no }}</button>
                    </form>
                    @if($style->current_version_id)
                        <form method="POST" action="{{ route('styles.discard-draft', $style) }}" class="mt-2" onsubmit="return confirm('Discard this draft?')">@csrf @method('DELETE')
                            <button class="btn btn-link btn-sm text-danger p-0">Discard draft</button>
                        </form>
                    @endif
                </x-cb.card>
            @endif

            @if($products->isNotEmpty() || $u->can('Manage product'))
                <x-cb.card title="Ready-made products" icon="ri-shopping-bag-3-line" class="mt-3" :count="$products->count()">
                    <x-slot:tools>
                        @can('Manage product')<a href="{{ route('products.create', ['style' => $style->id]) }}" class="action-btn"><i class="ri-add-line"></i>Ready-made version</a>@endcan
                    </x-slot:tools>
                    @forelse($products as $p)
                        <div class="d-flex justify-content-between py-1 border-bottom small">
                            <a href="{{ $u->can('Manage product') ? route('products.edit', $p) : route('products.index', ['q' => $p->code]) }}">{{ $p->name }}</a>
                            <span class="text-muted">{{ $p->variants->count() }} size(s)</span>
                        </div>
                    @empty
                        <p class="small text-muted mb-0">Sell this design off the rack by adding a ready-made product with sizes.</p>
                    @endforelse
                </x-cb.card>
            @endif

            <x-cb.card title="Version history" icon="ri-history-line" class="mt-3" :flush="true">
                <table class="cb-table stack mb-0">
                    <thead><tr><th>Version</th><th>Status</th><th>Published</th><th>Notes</th></tr></thead>
                    <tbody>
                    @foreach($versions as $v)
                        <tr @if($v->id === $version->id) style="background:rgba(10,147,150,.06)" @endif>
                            <td data-label="Version"><a href="{{ route('styles.show', [$style, 'version' => $v->version_no]) }}">v{{ $v->version_no }}</a></td>
                            <td data-label="Status"><span class="status-pill {{ ['draft' => 'st-warning', 'published' => 'st-success', 'retired' => 'st-muted'][$v->status] }}">{{ $v->status === 'published' ? 'Live' : ucfirst($v->status) }}</span></td>
                            <td data-label="Published">{{ $v->published_at ? $v->published_at->timezone($tz)->format('d M Y') . ($v->publisher ? ' · ' . $v->publisher->name : '') : '—' }}</td>
                            <td data-label="Notes" class="small">{{ $v->change_notes ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-cb.card>
        </div>
    </div>
    @endif

</div>
</div>
</div>
@endsection
