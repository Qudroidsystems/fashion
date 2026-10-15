@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stock ledger" icon="ri-git-commit-line" subtitle="Every stock change, newest first. Entries are never edited or deleted — corrections appear as new lines." />
    @include('inventory.partials.nav')

    <x-cb.card title="Movements" icon="ri-list-check" :count="$movements->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            @if($filterItem)
                <input type="hidden" name="item" value="{{ $filterItem->public_id }}">
                <span class="term-chip">{{ $filterItem->name }} <a href="{{ route('inventory.movements', request()->except('item')) }}" class="ms-1"><i class="ri-close-line"></i></a></span>
            @endif
            <select name="branch" class="cb-select" onchange="this.form.submit()">
                <option value="">All locations</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch') == $b->id)>{{ $b->name }}</option>@endforeach
            </select>
            <select name="type" class="cb-select" onchange="this.form.submit()">
                <option value="">All kinds</option>
                @foreach(\App\Models\StockMovement::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="cb-select" title="From">
            <input type="date" name="to" value="{{ request('to') }}" class="cb-select" title="To">
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>
        @include('inventory.partials.movement-table', ['movements' => $movements, 'showItem' => true])
    </x-cb.card>
    <div class="mt-3">{{ $movements->links() }}</div>

</div>
</div>
</div>
@endsection
