@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <x-cb.hero title="Product reviews" icon="ri-star-line" subtitle="Reviews show in the shop only after you publish them." />
    @include('store-admin.nav')
    @include('inventory.partials.flash')
    <ul class="nav nav-tabs mb-3">
        @foreach(['pending' => 'Waiting', 'approved' => 'Published', 'rejected' => 'Hidden', 'all' => 'All'] as $k => $l)
            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="?status={{ $k }}">{{ $l }}@if($k !== 'all' && ($counts[$k] ?? 0))<span class="badge rounded-pill {{ $k === 'pending' ? 'bg-danger' : 'bg-secondary' }} ms-1">{{ $counts[$k] }}</span>@endif</a></li>
        @endforeach
    </ul>
    @forelse($reviews as $r)
        <div class="cb-card mb-3"><div class="cb-card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div><span class="text-warning">@for($i = 1; $i <= 5; $i++)<i class="{{ $i <= $r->rating ? 'ri-star-fill' : 'ri-star-line' }}"></i>@endfor</span>
                    <strong class="ms-2">{{ $r->title }}</strong>
                    <div class="small text-muted">{{ $r->name }}{{ $r->email ? ' · ' . $r->email : '' }}{{ $r->verified_buyer ? ' · verified buyer' : '' }} · {{ $r->created_at->format('d M Y') }} · on <strong>{{ $r->product?->name }}</strong></div></div>
                <span class="status-pill {{ ['pending' => 'st-warning', 'approved' => 'st-success', 'rejected' => 'st-muted'][$r->status] }}">{{ ucfirst($r->status) }}</span>
            </div>
            <p class="mt-2 mb-2">{{ $r->body }}</p>
            <form method="POST" action="{{ route('store.reviews.update', $r) }}" class="d-flex gap-2 flex-wrap">@csrf @method('PATCH')
                <input name="reply" class="form-control form-control-sm" style="flex:1 1 280px" placeholder="Public reply (optional)" maxlength="1000" value="{{ $r->reply }}">
                <button name="status" value="approved" class="action-btn btn-primary-cb"><i class="ri-check-line"></i>Publish</button>
                <button name="status" value="rejected" class="action-btn"><i class="ri-eye-off-line"></i>Hide</button>
            </form>
        </div></div>
    @empty
        <x-cb.card><div class="empty-state"><i class="ri-star-line"></i><h6>No reviews here</h6></div></x-cb.card>
    @endforelse
    {{ $reviews->links() }}
</div></div></div>
@endsection
