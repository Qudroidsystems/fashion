@extends('sites.layout')
@section('title', $category?->name ?? (! empty($filters['q']) ? 'Search: ' . $filters['q'] : ($shopOpen ? 'Shop' : 'Ready to wear')))

@section('body')
    @include('sites.partials.page-title', [
        'title' => $category?->name ?? (! empty($filters['q']) ? '“' . $filters['q'] . '”' : ($shopOpen ? 'Shop' : 'Ready to wear')),
        'crumbs' => $category || ! empty($filters['q']) ? ['Shop' => $base . '/shop'] : [],
        'subtitle' => $shopOpen ? null : 'Pieces you can take home today, in the sizes shown. Ask us to hold one for you.',
    ])
    <section class="flat-spacing">
        <div class="container">
            <div class="row">
                <div class="col-xl-3 d-none d-xl-block"><div class="store-sidebar">@include('sites.partials.shop-filters', ['id' => 'd'])</div></div>
                <div class="col-xl-9">
                    <div class="tf-shop-control">
                        <div class="tf-control-filter d-xl-none">
                            <a href="#filterShop" data-bs-toggle="offcanvas" aria-controls="filterShop" class="tf-btn-filter"><span class="icon icon-filter"></span><span class="text">Filters</span></a>
                        </div>
                        <p class="text-caption-1 text-secondary mb-0">{{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}</p>
                        <div class="tf-control-sorting ms-auto">
                            <p class="d-none d-lg-block text-caption-1">Sort by:</p>
                            <form method="GET" action="{{ $base }}/shop">
                                @foreach(collect($filters)->except('sort') as $k => $v)
                                    @if(is_array($v))@foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach @elseif($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                                @endforeach
                                <select name="sort" class="store-select" onchange="this.form.submit()" aria-label="Sort products">
                                    @foreach($sorts as $k => $l)<option value="{{ $k }}" @selected(($filters['sort'] ?? 'featured') === $k)>{{ $l }}</option>@endforeach
                                </select>
                            </form>
                        </div>
                    </div>

                    @if($products->isEmpty())
                        <div class="store-empty">
                            <i class="icon icon-search2"></i>
                            <h5>Nothing here yet</h5>
                            <p class="text-secondary">@if(collect($filters)->except('sort')->filter()->isNotEmpty())Try fewer filters, or <a href="{{ $base }}/shop" class="link">see everything</a>.@else New pieces are on the way. <a href="{{ $base }}/collection" class="link">See the made-to-measure collection</a>.@endif</p>
                        </div>
                    @else
                        <div class="tf-grid-layout wrapper-shop tf-col-2 md-col-3 xl-col-3">
                            @foreach($products as $p)@include('sites.partials.product-card', ['p' => $p])@endforeach
                        </div>
                        @if($products->hasPages())
                            <ul class="wg-pagination justify-content-center mt-4">
                                @if(! $products->onFirstPage())<li><a href="{{ $products->previousPageUrl() }}" class="pagination-item text-button" aria-label="Previous"><i class="icon-arrLeft"></i></a></li>@endif
                                @foreach(range(max(1, $products->currentPage() - 2), min($products->lastPage(), $products->currentPage() + 2)) as $pg)
                                    <li class="{{ $pg === $products->currentPage() ? 'active' : '' }}">@if($pg === $products->currentPage())<div class="pagination-item text-button">{{ $pg }}</div>@else<a href="{{ $products->url($pg) }}" class="pagination-item text-button">{{ $pg }}</a>@endif</li>
                                @endforeach
                                @if($products->hasMorePages())<li><a href="{{ $products->nextPageUrl() }}" class="pagination-item text-button" aria-label="Next"><i class="icon-arrRight"></i></a></li>@endif
                            </ul>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="offcanvas offcanvas-start canvas-filter" id="filterShop">
        <div class="canvas-wrapper">
            <div class="canvas-header"><h5>Filters</h5><span class="icon-close icon-close-popup" data-bs-dismiss="offcanvas" aria-label="Close"></span></div>
            <div class="canvas-body">@include('sites.partials.shop-filters', ['id' => 'm'])</div>
        </div>
    </div>
@endsection
