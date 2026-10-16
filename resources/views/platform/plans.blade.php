@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Support\Money; @endphp
    <x-cb.hero title="Plans" icon="ri-price-tag-3-line" subtitle="What fashion houses can subscribe to. Changes apply to every house on the plan straight away; new prices apply from each house's next payment.">
        <x-slot:actions><button class="cb-hero-btn" data-bs-toggle="collapse" data-bs-target="#newPlan"><i class="ri-add-line me-1"></i>New plan</button></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    <div class="cb-banner info"><i class="ri-information-line"></i><div>Leave a limit empty for unlimited. Houses over a limit keep everything they have; they just can't add more. Customers, measurements, styles, quotes, orders, invoices and the production board are in every plan.</div></div>

    @foreach($plans->push(null) as $plan)
        @php $isNew = $plan === null; $p = $plan ?? new \App\Models\Plan(['limits' => [], 'features' => [], 'is_active' => true, 'is_public' => true]); @endphp
        <div class="{{ $isNew ? 'collapse' : '' }} mb-3" @if($isNew) id="newPlan" @endif>
        <x-cb.card :title="$isNew ? 'New plan' : $p->name . ($p->is_free ? ' (free plan)' : '')" icon="ri-vip-crown-line">
            <x-slot:tools>@unless($isNew)<span class="small text-muted">{{ $counts[$p->id] ?? 0 }} house(s)</span>@endunless</x-slot:tools>
            <form method="POST" action="{{ $isNew ? route('platform.plans.store') : route('platform.plans.update', $p) }}">@csrf @unless($isNew) @method('PUT') @endunless
                <div class="row g-3">
                    <div class="col-md-2"><label class="form-label small">Code</label><input name="code" class="form-control form-control-sm font-monospace" required value="{{ $p->code }}"></div>
                    <div class="col-md-3"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm" required value="{{ $p->name }}"></div>
                    <div class="col-md-7"><label class="form-label small">Tagline</label><input name="tagline" class="form-control form-control-sm" value="{{ $p->tagline }}"></div>
                    <div class="col-md-3"><label class="form-label small">Price a month</label><div class="input-group input-group-sm"><span class="input-group-text">{{ Money::symbol() }}</span><input type="number" step="0.01" min="0" name="price_monthly" class="form-control" required value="{{ $p->price_monthly ?? 0 }}"></div></div>
                    <div class="col-md-3"><label class="form-label small">Price a year</label><div class="input-group input-group-sm"><span class="input-group-text">{{ Money::symbol() }}</span><input type="number" step="0.01" min="0" name="price_yearly" class="form-control" required value="{{ $p->price_yearly ?? 0 }}"></div></div>
                    @foreach($limits as $k => $l)
                        <div class="col-md-2"><label class="form-label small">{{ $l[0] }}</label><input type="number" min="1" name="limits[{{ $k }}]" class="form-control form-control-sm" value="{{ $p->limit($k) }}" placeholder="Unlimited"></div>
                    @endforeach
                    <div class="col-12"><label class="form-label small">Features</label>
                        <div class="row">@foreach($features as $k => $label)
                            <div class="col-md-4"><label class="d-flex gap-2 small"><input type="checkbox" name="features[]" value="{{ $k }}" @checked($p->has($k))> {{ $label }}</label></div>
                        @endforeach</div></div>
                    <div class="col-12 d-flex flex-wrap gap-4 small">
                        <label class="d-flex gap-1"><input type="checkbox" name="is_active" value="1" @checked($p->is_active)> Can be chosen</label>
                        <label class="d-flex gap-1"><input type="checkbox" name="is_public" value="1" @checked($p->is_public)> Shown on the website</label>
                        <label class="d-flex gap-1"><input type="checkbox" name="highlight" value="1" @checked($p->highlight)> Mark as most chosen</label>
                        <label class="d-flex gap-1"><input type="checkbox" name="is_free" value="1" @checked($p->is_free)> Free plan (where unpaid houses go)</label>
                        <label class="d-flex gap-1 align-items-center">Order <input type="number" name="sort_order" min="0" max="100" class="form-control form-control-sm" style="width:70px" value="{{ $p->sort_order }}"></label>
                    </div>
                    <div class="col-12"><button class="action-btn btn-primary-cb"><i class="ri-save-line"></i>{{ $isNew ? 'Add plan' : 'Save ' . $p->name }}</button></div>
                </div>
            </form>
        </x-cb.card>
        </div>
    @endforeach
</div>
</div>
</div>
@endsection
