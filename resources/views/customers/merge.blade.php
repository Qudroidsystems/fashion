@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Merge duplicate customer" icon="ri-git-merge-line"
               :subtitle="'Choose the record to keep. ' . $customer->name . ' (' . $customer->customer_number . ') will be merged into it.'"
               :back="route('customers.show', $customer)" back-label="Back to customer" />

    <div class="cb-banner info"><i class="ri-information-line"></i>
        <div>Notes, follow-ups, tags and consent move to the record you keep, and its empty fields are filled from this one.
        {{ $customer->customer_number }} stays as a “merged” record pointing at the kept customer, so nothing is lost.</div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <x-cb.card title="Duplicate (will be merged)" icon="ri-user-unfollow-line">
                <div class="fw-semibold">{{ $customer->full_name }}</div>
                <div class="small text-muted">{{ $customer->customer_number }}</div>
                <div class="small mt-2">{{ $customer->phone }}<br>{{ $customer->email }}</div>
            </x-cb.card>
        </div>
        <div class="col-lg-8">
            <x-cb.card title="Keep which record?" icon="ri-user-follow-line" :flush="true">
                <form method="GET" class="cb-toolbar">
                    <div class="cb-search"><i class="ri-search-line"></i><input name="q" value="{{ request('q') }}" placeholder="Search another customer…"></div>
                    <button class="action-btn btn-primary-cb">Search</button>
                </form>
                @forelse($candidates as $cand)
                    <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">{{ $cand->full_name }} <span class="small text-muted">{{ $cand->customer_number }}</span></div>
                            <div class="small text-muted">{{ $cand->phone }} {{ $cand->email }}
                                @if(!empty($cand->match_reasons)) · same {{ implode(' and ', $cand->match_reasons) }}@endif
                            </div>
                        </div>
                        <form method="POST" action="{{ route('customers.merge', $customer) }}"
                              onsubmit="return confirm('Merge {{ addslashes($customer->name) }} into {{ addslashes($cand->name) }}? This cannot be undone automatically.')">
                            @csrf
                            <input type="hidden" name="target" value="{{ $cand->public_id }}">
                            <button class="action-btn btn-primary-cb"><i class="ri-git-merge-line"></i>Keep this one</button>
                        </form>
                    </div>
                @empty
                    <div class="empty-state"><i class="ri-user-search-line"></i><h6>No likely duplicates found</h6><p>Search for the customer to keep.</p></div>
                @endforelse
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
