@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; @endphp

    <x-cb.hero title="Ready-made products" icon="ri-shopping-bag-3-line" subtitle="Items sold off the rack, with a SKU and optional barcode for each size and colour.">
        <x-slot:actions>
            @can('Manage product')
                <a href="{{ route('catalogue-categories.index') }}" class="cb-hero-btn"><i class="ri-price-tag-3-line me-1"></i>Categories</a>
                <a href="{{ route('products.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New product</a>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif

    <x-cb.card title="Products" icon="ri-list-check" :count="$products->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, code, SKU or scan a barcode…" autofocus>
            </div>
            <select name="category" class="cb-select" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select name="status" class="cb-select" onchange="this.form.submit()">
                @foreach(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>

        @if($products->isEmpty())
            <div class="empty-state"><i class="ri-shopping-bag-3-line"></i><h6>No products</h6><p>Add ready-made items you sell from stock.</p></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th style="width:56px"></th><th>Product</th><th>Category</th><th>Sizes / colours</th><th>Price</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach($products as $p)
                    @php [$min, $max] = $p->priceRange(); @endphp
                    <tr>
                        <td>
                            @if($p->image_url)<img src="{{ $p->image_url }}" alt="" class="rounded-2" style="width:44px;height:52px;object-fit:cover">
                            @else<div class="rounded-2 d-grid" style="width:44px;height:52px;background:#f3f5f9"><i class="ri-shopping-bag-3-line m-auto text-muted"></i></div>@endif
                        </td>
                        <td data-label="Product"><div class="fw-semibold">{{ $p->name }}</div><div class="small text-muted">{{ $p->code }}</div></td>
                        <td data-label="Category">{{ $p->category?->name ?? '—' }}</td>
                        <td data-label="Sizes / colours">
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($p->variants->take(6) as $v)<span class="term-chip {{ $v->is_active ? '' : 'opacity-50' }}">{{ $v->label }}</span>@endforeach
                                @if($p->variants->count() > 6)<span class="small text-muted">+{{ $p->variants->count() - 6 }}</span>@endif
                            </div>
                        </td>
                        <td data-label="Price">{{ $min === null ? '—' : ($min == $max ? Money::format($min) : Money::format($min) . ' – ' . Money::format($max)) }}</td>
                        <td data-label="Status">
                            <span class="status-pill {{ $p->status === 'active' ? 'st-success' : 'st-muted' }}">{{ ucfirst($p->status) }}</span>
                            @if($p->show_in_catalogue && $p->status === 'active')<i class="ri-eye-line text-muted ms-1" title="In customer catalogue"></i>@endif
                        </td>
                        <td class="text-end">
                            @can('Manage product')<a href="{{ route('products.edit', $p) }}" class="action-btn"><i class="ri-pencil-line"></i>Edit</a>@endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $products->links() }}</div>

</div>
</div>
</div>
@endsection
