{{-- Shop filters (desktop sidebar and mobile drawer). GET form, so links can be shared. --}}
<form method="GET" action="{{ $base }}/shop" class="store-filters">
    @if(! empty($filters['q']))<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
    <input type="hidden" name="sort" value="{{ $filters['sort'] ?? 'featured' }}">
    @if(count($menuCategories))
    <div class="widget-facet facet-categories">
        <h6 class="facet-title">Categories</h6>
        <ul class="facet-content">
            <li><label class="categories-item {{ empty($filters['category']) ? 'active' : '' }}"><input type="radio" name="category" value="" class="d-none" @checked(empty($filters['category'])) onchange="this.form.submit()"> All</label></li>
            @foreach($menuCategories as $c)
                <li><label class="categories-item {{ (int) ($filters['category'] ?? 0) === $c->id ? 'active' : '' }}"><input type="radio" name="category" value="{{ $c->id }}" class="d-none" @checked((int) ($filters['category'] ?? 0) === $c->id) onchange="this.form.submit()"> {{ $c->name }} <span class="count-cate">({{ $c->shop_count }})</span></label></li>
            @endforeach
        </ul>
    </div>
    @endif
    @if($facets['max'] > 0)
    <div class="widget-facet facet-price">
        <h6 class="facet-title">Price</h6>
        <div class="d-flex gap-2 align-items-center">
            <input type="number" name="min" min="0" step="any" value="{{ $filters['min'] ?? '' }}" placeholder="{{ \App\Support\Money::symbol() }}{{ number_format($facets['min']) }}" aria-label="Lowest price" class="store-input-sm">
            <span>–</span>
            <input type="number" name="max" min="0" step="any" value="{{ $filters['max'] ?? '' }}" placeholder="{{ \App\Support\Money::symbol() }}{{ number_format($facets['max']) }}" aria-label="Highest price" class="store-input-sm">
        </div>
    </div>
    @endif
    @if($facets['sizes']->isNotEmpty())
    <div class="widget-facet facet-size">
        <h6 class="facet-title">Size</h6>
        <div class="facet-size-box size-box store-checks">
            @foreach($facets['sizes'] as $s)
                <label class="size-item {{ in_array($s, (array) ($filters['size'] ?? []), true) ? 'active' : '' }}"><input type="checkbox" name="size[]" value="{{ $s }}" @checked(in_array($s, (array) ($filters['size'] ?? []), true))> {{ $s }}</label>
            @endforeach
        </div>
    </div>
    @endif
    @if($facets['colours']->isNotEmpty())
    <div class="widget-facet facet-color">
        <h6 class="facet-title">Colour</h6>
        <div class="facet-color-box store-checks">
            @foreach($facets['colours'] as $c)
                <label class="color-item {{ in_array($c['name'], (array) ($filters['colour'] ?? []), true) ? 'active' : '' }}"><input type="checkbox" name="colour[]" value="{{ $c['name'] }}" @checked(in_array($c['name'], (array) ($filters['colour'] ?? []), true))><span class="color" style="background:{{ $c['hex'] }}"></span>{{ $c['name'] }}</label>
            @endforeach
        </div>
    </div>
    @endif
    <div class="widget-facet facet-fieldset">
        <h6 class="facet-title">Show</h6>
        <div class="box-fieldset-item">
            <fieldset class="fieldset-item"><input type="checkbox" name="in_stock" value="1" class="tf-check" id="f-stock-{{ $id ?? 'a' }}" @checked(! empty($filters['in_stock']))><label for="f-stock-{{ $id ?? 'a' }}">In stock only</label></fieldset>
            <fieldset class="fieldset-item"><input type="checkbox" name="sale" value="1" class="tf-check" id="f-sale-{{ $id ?? 'a' }}" @checked(! empty($filters['sale']))><label for="f-sale-{{ $id ?? 'a' }}">On sale</label></fieldset>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="tf-btn btn-fill flex-grow-1"><span class="text">Apply</span></button>
        <a href="{{ $base }}/shop" class="tf-btn btn-white has-border">Reset</a>
    </div>
</form>
