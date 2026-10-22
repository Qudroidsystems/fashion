@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php $edit = request('edit') ? $banners->firstWhere('id', (int) request('edit')) : null; @endphp
    <x-cb.hero title="Homepage banners" icon="ri-slideshow-3-line" subtitle="Big slides at the top of your shop’s home page, and promotion banners further down." />
    @include('store-admin.nav')
    @include('inventory.partials.flash')
    <div class="row g-3">
        <div class="col-xl-7">
            @forelse($banners as $b)
                <div class="cb-card mb-3 {{ $b->is_active ? '' : 'opacity-50' }}"><div class="d-flex gap-3 p-3 align-items-center">
                    <img src="{{ $b->image_url }}" alt="" class="rounded-2" style="width:160px;height:90px;object-fit:cover">
                    <div class="flex-grow-1"><span class="term-chip">{{ $b->placement === 'hero' ? 'Slide' : 'Promotion' }}</span>
                        <div class="fw-semibold">{{ $b->title }}</div><div class="small text-muted">{{ $b->subtitle }}</div>
                        <div class="small text-muted">{{ $b->button_text }} → {{ $b->link ?: '/shop' }}@if($b->ends_at) · until {{ $b->ends_at->format('d M Y') }}@endif</div></div>
                    <div class="text-nowrap"><a href="?edit={{ $b->id }}" class="action-btn"><i class="ri-pencil-line"></i></a>
                        <form method="POST" action="{{ route('store.banners.destroy', $b) }}" class="d-inline" onsubmit="return confirm('Remove this banner?')">@csrf @method('DELETE')<button class="action-btn text-danger"><i class="ri-delete-bin-line"></i></button></form></div>
                </div></div>
            @empty
                <x-cb.card><div class="empty-state"><i class="ri-slideshow-3-line"></i><h6>No banners</h6><p>Without slides the home page shows your website headline and hero picture.</p></div></x-cb.card>
            @endforelse
        </div>
        <div class="col-xl-5">
            <x-cb.card :title="$edit ? 'Edit banner' : 'New banner'" icon="ri-add-line">
                <form method="POST" action="{{ $edit ? route('store.banners.update', $edit) : route('store.banners.store') }}" enctype="multipart/form-data" class="row g-2">
                    @csrf
                    <div class="col-6"><label class="form-label">Where</label><select name="placement" class="form-select"><option value="hero" @selected(old('placement', $edit?->placement) === 'hero')>Top slide</option><option value="promo" @selected(old('placement', $edit?->placement) === 'promo')>Promotion</option></select></div>
                    <div class="col-6"><label class="form-label">Text colour</label><select name="text_colour" class="form-select"><option value="light" @selected(old('text_colour', $edit?->text_colour) === 'light')>White</option><option value="dark" @selected(old('text_colour', $edit?->text_colour) === 'dark')>Dark</option></select></div>
                    <div class="col-12"><label class="form-label">Small line above</label><input name="eyebrow" class="form-control" maxlength="80" value="{{ old('eyebrow', $edit?->eyebrow) }}" placeholder="NEW SEASON"></div>
                    <div class="col-12"><label class="form-label">Title *</label><input name="title" class="form-control" required maxlength="120" value="{{ old('title', $edit?->title) }}"></div>
                    <div class="col-12"><label class="form-label">Subtitle</label><input name="subtitle" class="form-control" maxlength="200" value="{{ old('subtitle', $edit?->subtitle) }}"></div>
                    <div class="col-6"><label class="form-label">Button</label><input name="button_text" class="form-control" maxlength="40" value="{{ old('button_text', $edit?->button_text) }}" placeholder="Shop now"></div>
                    <div class="col-6"><label class="form-label">Link</label><input name="link" class="form-control" maxlength="255" value="{{ old('link', $edit?->link) }}" placeholder="shop?sale=1"></div>
                    <div class="col-12"><label class="form-label">Picture {{ $edit ? '' : '*' }}</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control" {{ $edit ? '' : 'required' }}>
                        <div class="small text-muted">Slides: wide, about 1920 × 800. Promotions: about 900 × 600.</div></div>
                    <div class="col-6"><label class="form-label">Show from</label><input type="date" name="starts_at" class="form-control" value="{{ old('starts_at', $edit?->starts_at?->toDateString()) }}"></div>
                    <div class="col-6"><label class="form-label">Until</label><input type="date" name="ends_at" class="form-control" value="{{ old('ends_at', $edit?->ends_at?->toDateString()) }}"></div>
                    <div class="col-6"><label class="form-label">Order</label><input type="number" min="0" name="sort_order" class="form-control" value="{{ old('sort_order', $edit?->sort_order ?? 0) }}"></div>
                    <div class="col-6 d-flex align-items-end"><label class="d-flex gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $edit?->is_active ?? true))> Active</label></div>
                    <div class="col-12 d-flex gap-2"><button class="action-btn btn-primary-cb flex-grow-1 justify-content-center"><i class="ri-save-line"></i>Save</button>@if($edit)<a href="{{ route('store.banners.index') }}" class="action-btn">Cancel</a>@endif</div>
                </form>
            </x-cb.card>
        </div>
    </div>
</div></div></div>
@endsection
