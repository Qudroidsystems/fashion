{{-- Topbar fashion-house switcher: multi-tenant mode, users with more than one active house. --}}
@php
    $switchable = (config('tenancy.mode') !== 'single' && auth()->check())
        ? auth()->user()->activeTenants()->orderBy('name')->get(['tenants.id', 'tenants.public_id', 'tenants.name'])
        : collect();
@endphp
@if($switchable->count() > 1)
<div class="position-relative me-1" id="ts-wrapper">
    <button type="button" id="ts-btn" class="btn btn-sm btn-ghost-dark d-flex align-items-center gap-2" style="border-radius:10px;padding:6px 10px;" aria-haspopup="true" aria-expanded="false">
        <i class="ri-store-2-line fs-16"></i>
        <span class="d-none d-lg-inline fw-medium" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $currentTenant?->name ?? 'Choose house' }}</span>
        <i class="ri-arrow-down-s-line"></i>
    </button>
    <div id="ts-dropdown" style="display:none;position:absolute;top:calc(100% + 8px);right:0;min-width:240px;background:var(--vz-dropdown-bg,#fff);border:1px solid var(--vz-border-color,#e9ebec);border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:9999;padding:6px;">
        <div class="px-2 py-1 small text-muted text-uppercase" style="font-size:10px;">Switch fashion house</div>
        @foreach($switchable as $t)
            <form method="POST" action="{{ route('tenant.switch') }}">
                @csrf
                <input type="hidden" name="tenant" value="{{ $t->public_id }}">
                <button type="submit" class="dropdown-item d-flex align-items-center gap-2 rounded-2 {{ $currentTenant?->id === $t->id ? 'active' : '' }}">
                    <i class="ri-store-line"></i><span class="flex-grow-1 text-truncate">{{ $t->name }}</span>
                    @if($currentTenant?->id === $t->id)<i class="ri-check-line"></i>@endif
                </button>
            </form>
        @endforeach
    </div>
</div>
<script>
(function () {
    const btn = document.getElementById('ts-btn'), dd = document.getElementById('ts-dropdown');
    btn.addEventListener('click', e => { e.stopPropagation(); const open = dd.style.display === 'none'; dd.style.display = open ? 'block' : 'none'; btn.setAttribute('aria-expanded', open); });
    document.addEventListener('click', e => { if (!document.getElementById('ts-wrapper').contains(e.target)) dd.style.display = 'none'; });
})();
</script>
@endif
