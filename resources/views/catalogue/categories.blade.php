@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Catalogue categories" icon="ri-price-tag-3-line" subtitle="Shared by bespoke styles and ready-made products."
               :back="route('styles.index')" back-label="Styles" />

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <div class="row g-3">
        <div class="col-lg-8">
            <x-cb.card title="Categories" icon="ri-list-check" :count="$categories->count()" :flush="true">
                <table class="cb-table stack mb-0">
                    <thead><tr><th>Name</th><th>Order</th><th>Used by</th><th>Shown</th><th></th></tr></thead>
                    <tbody>
                    @foreach($categories as $c)
                        <tr>
                            <td data-label="Name"><input form="cat-{{ $c->id }}" name="name" class="form-control form-control-sm" value="{{ $c->name }}" maxlength="80" required></td>
                            <td data-label="Order" style="width:90px"><input form="cat-{{ $c->id }}" type="number" name="sort_order" class="form-control form-control-sm" value="{{ $c->sort_order }}" min="0" max="999"></td>
                            <td data-label="Used by" class="small text-muted">{{ $c->styles_count }} styles · {{ $c->products_count }} products</td>
                            <td data-label="Shown"><input form="cat-{{ $c->id }}" type="checkbox" name="is_active" value="1" @checked($c->is_active)></td>
                            <td class="text-end text-nowrap">
                                <form id="cat-{{ $c->id }}" method="POST" action="{{ route('catalogue-categories.update', $c) }}" class="d-inline">@csrf @method('PUT')
                                    <button class="action-btn"><i class="ri-save-line"></i>Save</button></form>
                                <form method="POST" action="{{ route('catalogue-categories.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete this category?')">@csrf @method('DELETE')
                                    <button class="action-btn text-danger" title="Delete"><i class="ri-delete-bin-line"></i></button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-cb.card>
        </div>
        <div class="col-lg-4">
            <x-cb.card title="Add category" icon="ri-add-line">
                <form method="POST" action="{{ route('catalogue-categories.store') }}" class="d-flex gap-2">@csrf
                    <input name="name" class="form-control" required maxlength="80" placeholder="e.g. Bridal">
                    <button class="action-btn btn-primary-cb"><i class="ri-add-line"></i>Add</button>
                </form>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
