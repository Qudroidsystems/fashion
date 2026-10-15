@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="New measurements" icon="ri-ruler-2-line" :subtitle="'For ' . $customer->full_name . ' · choose what you are measuring for'"
               :back="route('customers.show', $customer) . '#measurements'" back-label="Back to customer" />

    <div class="row g-3">
        @forelse($templates as $t)
            <div class="col-md-6 col-xl-4">
                <a href="{{ route('measurements.create', ['customer' => $customer, 'template_version_id' => $t->current_version_id]) }}" class="text-decoration-none">
                    <x-cb.card :title="$t->name" icon="ri-t-shirt-line">
                        <div class="small text-muted">{{ $t->garment_category }} · v{{ $t->currentVersion->version_no }}</div>
                        @if($t->description)<div class="small mt-1">{{ $t->description }}</div>@endif
                    </x-cb.card>
                </a>
            </div>
        @empty
            <div class="col-12"><div class="empty-state"><i class="ri-file-list-2-line"></i><h6>No published templates</h6><p>Ask a manager to publish a measurement template first.</p></div></div>
        @endforelse
    </div>

</div>
</div>
</div>
@endsection
