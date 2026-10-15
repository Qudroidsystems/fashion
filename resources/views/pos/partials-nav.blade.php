<ul class="nav nav-pills gap-1 mb-3 flex-nowrap overflow-auto pb-1">
    <li class="nav-item"><a href="{{ route('pos.terminal') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('pos.terminal') ? 'active' : '' }}"><i class="ri-shopping-cart-2-line me-1"></i>Till</a></li>
    <li class="nav-item"><a href="{{ route('pos.sales.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('pos.sales.*') ? 'active' : '' }}"><i class="ri-receipt-line me-1"></i>Sales</a></li>
    <li class="nav-item"><a href="{{ route('pos.shifts.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('pos.shifts.*') ? 'active' : '' }}"><i class="ri-safe-2-line me-1"></i>Shifts</a></li>
</ul>
