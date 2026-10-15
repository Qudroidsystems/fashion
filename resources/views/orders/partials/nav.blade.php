@php $u = auth()->user(); @endphp
<ul class="nav nav-pills gap-1 mb-3 flex-nowrap overflow-auto pb-1" style="scrollbar-width:thin">
    @can('View order')<li class="nav-item"><a href="{{ route('orders.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('orders.*') ? 'active' : '' }}"><i class="ri-file-list-3-line me-1"></i>Orders</a></li>@endcan
    @can('Manage quotation')<li class="nav-item"><a href="{{ route('quotations.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('quotations.*') ? 'active' : '' }}"><i class="ri-draft-line me-1"></i>Quotations</a></li>@endcan
    @can('View payment')
        <li class="nav-item"><a href="{{ route('invoices.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('invoices.*') ? 'active' : '' }}"><i class="ri-bill-line me-1"></i>Invoices</a></li>
        <li class="nav-item"><a href="{{ route('payments.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('payments.*', 'refunds.*') ? 'active' : '' }}"><i class="ri-bank-card-line me-1"></i>Payments</a></li>
    @endcan
</ul>
