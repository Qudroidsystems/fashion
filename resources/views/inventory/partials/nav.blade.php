{{-- Sub-navigation shared by the inventory pages. --}}
@php
    $u = auth()->user();
    $tabs = [
        ['inventory.index', 'inventory.items.*', 'ri-archive-line', 'Stock', 'View inventory'],
        ['inventory.movements', 'inventory.movements', 'ri-git-commit-line', 'Ledger', 'View inventory'],
        ['purchase-orders.index', 'purchase-orders.*', 'ri-file-list-2-line', 'Purchase orders', 'View inventory'],
        ['goods-receipts.index', 'goods-receipts.*', 'ri-inbox-archive-line', 'Received', 'View inventory'],
        ['stock-transfers.index', 'stock-transfers.*', 'ri-arrow-left-right-line', 'Transfers', 'View inventory'],
        ['stock-adjustments.index', 'stock-adjustments.*', 'ri-scales-3-line', 'Adjustments & counts', 'View inventory'],
        ['material-issues.index', 'material-issues.*', 'ri-scissors-cut-line', 'Material issues', 'View inventory'],
        ['suppliers.index', 'suppliers.*', 'ri-truck-line', 'Suppliers', 'View inventory'],
    ];
@endphp
<ul class="nav nav-pills gap-1 mb-3 flex-nowrap overflow-auto pb-1" style="scrollbar-width:thin">
    @foreach($tabs as [$route, $pattern, $icon, $label, $perm])
        @if($u->can($perm))
            <li class="nav-item"><a href="{{ route($route) }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs($pattern) ? 'active' : '' }}"><i class="{{ $icon }} me-1"></i>{{ $label }}</a></li>
        @endif
    @endforeach
</ul>
