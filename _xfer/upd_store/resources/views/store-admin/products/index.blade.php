@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; $canEdit = auth()->user()->can('Manage store products'); @endphp
    <x-cb.hero title="Products & pictures" icon="ri-image-2-line" subtitle="What your shop shows: pictures, prices, sale prices and visibility. Sizes and colours are added on the product itself.">
        <x-slot:actions>
            @can('Manage product')<a href="{{ route('products.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New product</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('store-admin.nav')
    @include('inventory.partials.flash')

    <x-cb.card title="Products" icon="ri-list-check" :count="$products->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Name, code or SKU…"></div>
            <select name="category" class="cb-select" onchange="this.form.submit()"><option value="">All categories</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach</select>
            <select name="show" class="cb-select" onchange="this.form.submit()">
                @foreach(['' => 'All active', 'online' => 'In the shop', 'hidden' => 'Hidden from shop', 'no_pictures' => 'Without pictures', 'archived' => 'Archived'] as $k => $l)<option value="{{ $k }}" @selected(request('show', '') === $k)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>
        @if($products->isEmpty())
            <div class="empty-state"><i class="ri-shopping-bag-3-line"></i><h6>No products</h6><p>Add ready-made products in Catalogue › Ready-made products, then give them pictures here.</p></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th style="width:64px"></th><th>Product</th><th>Sizes · price · stock</th><th>In shop</th><th></th></tr></thead>
            <tbody>
            @foreach($products as $p)
                <tr>
                    <td>@if($p->cover_url)<img src="{{ $p->cover_url }}" class="rounded-2" style="width:48px;height:60px;object-fit:cover" alt="">@else<div class="rounded-2 d-grid" style="width:48px;height:60px;background:#f3f5f9"><i class="ri-image-add-line m-auto text-muted"></i></div>@endif</td>
                    <td data-label="Product"><div class="fw-semibold">{{ $p->name }}</div><div class="small text-muted">{{ $p->code }} · {{ $p->category?->name ?? 'No category' }} · {{ $p->images->count() }} picture(s)</div>
                        @if($p->badge)<span class="term-chip">{{ $p->badge }}</span>@endif @if($p->is_featured)<span class="term-chip">Featured</span>@endif</td>
                    <td data-label="Sizes">
                        @foreach($p->variants->take(5) as $v)
                            <div class="small d-flex gap-2 align-items-center {{ $v->is_active ? '' : 'opacity-50' }}">
                                <span style="min-width:90px">{{ $v->label }}</span>
                                <span class="fw-semibold">{{ Money::format($v->price) }}</span>
                                @if($v->isOnSale())<span class="text-muted text-decoration-line-through">{{ Money::format($v->compare_at_price) }}</span>@endif
                                @if($canCost && $v->cost_price)<span class="text-muted">cost {{ Money::format($v->cost_price) }}</span>@endif
                                @php $left = $stock[$v->id] ?? null; @endphp
                                <span class="status-pill {{ $left === null ? 'st-muted' : ($left <= 0 ? 'st-danger' : 'st-success') }}">{{ $left === null ? 'not tracked' : $left . ' free' }}</span>
                            </div>
                        @endforeach
                        @if($p->variants->count() > 5)<div class="small text-muted">+{{ $p->variants->count() - 5 }} more</div>@endif
                    </td>
                    <td data-label="In shop">
                        @if($canEdit)
                        <form method="POST" action="{{ route('store.products.quick', $p) }}">@csrf
                            <input type="hidden" name="show_in_catalogue" value="0">
                            <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="show_in_catalogue" value="1" @checked($p->show_in_catalogue) onchange="this.form.submit()"></label>
                        </form>
                        @else{{ $p->show_in_catalogue ? 'Yes' : 'No' }}@endif
                    </td>
                    <td class="text-end">@if($canEdit)<a href="{{ route('store.products.edit', $p) }}" class="action-btn"><i class="ri-image-edit-line"></i>Pictures & prices</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $products->links() }}</div>
</div></div></div>
@endsection
