{{--
    Sidebar menu. Items are permission-gated with @can.
    "Soon" items are the SRS modules still to be built: when a module lands,
    replace its href with the real route and drop the badge.
--}}
@php
    $soon = [];   // every SRS module is now built
    $u = auth()->user();
    $ent = app(\App\Services\Billing\Entitlements::class);
    $locked = fn (string $feature) => $currentTenant && ! $ent->has($currentTenant, $feature);
@endphp

<li class="menu-title"><span data-key="t-menu">Menu</span></li>

@if($currentTenant)
    @can('View dashboard')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <i class="ph-gauge"></i> <span>Dashboard</span>
        </a>
    </li>
    @endcan

    {{-- FASHION HOUSE MODULES --}}
    @php $visibleSoon = collect($soon)->filter(fn ($m) => $u->can($m['perm'])); @endphp
    @if($visibleSoon->count() || $u->can('View customer') || $u->can('View measurement') || $u->can('View style') || $u->can('View product') || $u->can('View inventory') || $u->can('View order') || $u->can('Use POS') || $u->can('View production') || $u->can('View project') || $u->can('View reports') || $u->can('View attendance') || $u->can('View customer materials') || $u->can('Manage appointment') || $u->can('Send campaign'))
        <li class="menu-title"><i class="ri-more-fill"></i> <span>Fashion House</span></li>
    @endif

    @can('View customer')
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ request()->routeIs('customers.*', 'follow-ups.*') ? 'active' : '' }}" href="#sidebarCustomers" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ request()->routeIs('customers.*', 'follow-ups.*') ? 'true' : 'false' }}" aria-controls="sidebarCustomers">
            <i class="ri-user-heart-line"></i> <span>Customers</span>
        </a>
        <div class="collapse menu-dropdown {{ request()->routeIs('customers.*', 'follow-ups.*') ? 'show' : '' }}" id="sidebarCustomers">
            <ul class="nav nav-sm flex-column">
                <li class="nav-item"><a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.index') ? 'active' : '' }}">All customers</a></li>
                @can('Create customer')
                <li class="nav-item"><a href="{{ route('customers.create') }}" class="nav-link {{ request()->routeIs('customers.create') ? 'active' : '' }}">New customer</a></li>
                @endcan
                <li class="nav-item"><a href="{{ route('follow-ups.index') }}" class="nav-link {{ request()->routeIs('follow-ups.*') ? 'active' : '' }}">Follow-ups</a></li>
                @if(! $locked('website'))
                @php $newEnq = \App\Models\SiteEnquiry::where('status', 'new')->count(); @endphp
                <li class="nav-item"><a href="{{ route('site-enquiries.index') }}" class="nav-link {{ request()->routeIs('site-enquiries.*') ? 'active' : '' }}">Website enquiries @if($newEnq)<span class="badge rounded-pill bg-danger ms-1">{{ $newEnq }}</span>@endif</a></li>
                @endif
            </ul>
        </div>
    </li>
    @endcan

    @can('Send campaign')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('campaigns.*') ? 'active' : '' }}" href="{{ route('campaigns.index') }}">
            <i class="ri-megaphone-line"></i> <span>Campaigns</span>
        </a>
    </li>
    @endcan

    @can('Manage appointment')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('appointments.*') ? 'active' : '' }}" href="{{ route('appointments.index') }}">
            <i class="ri-calendar-todo-line"></i> <span>Appointments</span>
        </a>
    </li>
    @endcan

    @if($u->can('View measurement') || $u->can('Manage measurement templates'))
    @php $mActive = request()->routeIs('measurements.*', 'measurement-templates.*', 'measurement-parameters.*'); @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $mActive ? 'active' : '' }}" href="#sidebarMeasurements" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $mActive ? 'true' : 'false' }}" aria-controls="sidebarMeasurements">
            <i class="ri-ruler-2-line"></i> <span>Measurements</span>
        </a>
        <div class="collapse menu-dropdown {{ $mActive ? 'show' : '' }}" id="sidebarMeasurements">
            <ul class="nav nav-sm flex-column">
                @can('View measurement')
                <li class="nav-item"><a href="{{ route('measurements.index') }}" class="nav-link {{ request()->routeIs('measurements.*') ? 'active' : '' }}">Review queue</a></li>
                @endcan
                @can('Manage measurement templates')
                <li class="nav-item"><a href="{{ route('measurement-templates.index') }}" class="nav-link {{ request()->routeIs('measurement-templates.*') ? 'active' : '' }}">Templates</a></li>
                <li class="nav-item"><a href="{{ route('measurement-parameters.index') }}" class="nav-link {{ request()->routeIs('measurement-parameters.*') ? 'active' : '' }}">Parameters</a></li>
                @endcan
            </ul>
        </div>
    </li>
    @endif

    @if($u->can('View style') || $u->can('View product'))
    @php $cActive = request()->routeIs('styles.*', 'products.*', 'catalogue-categories.*'); @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $cActive ? 'active' : '' }}" href="#sidebarCatalogue" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $cActive ? 'true' : 'false' }}" aria-controls="sidebarCatalogue">
            <i class="ri-t-shirt-air-line"></i> <span>Catalogue</span>
        </a>
        <div class="collapse menu-dropdown {{ $cActive ? 'show' : '' }}" id="sidebarCatalogue">
            <ul class="nav nav-sm flex-column">
                @can('View style')
                <li class="nav-item"><a href="{{ route('styles.index') }}" class="nav-link {{ request()->routeIs('styles.*') ? 'active' : '' }}">Styles</a></li>
                @endcan
                @can('View product')
                <li class="nav-item"><a href="{{ route('products.index') }}" class="nav-link {{ request()->routeIs('products.*') ? 'active' : '' }}">Ready-made products</a></li>
                @endcan
                @if($u->can('Manage style') || $u->can('Manage product'))
                <li class="nav-item"><a href="{{ route('catalogue-categories.index') }}" class="nav-link {{ request()->routeIs('catalogue-categories.*') ? 'active' : '' }}">Categories</a></li>
                @endif
            </ul>
        </div>
    </li>
    @endif

    @if($u->can('View order') || $u->can('View payment') || $u->can('Manage quotation') || $u->can('Manage expense'))
    @php $oActive = request()->routeIs('orders.*', 'quotations.*', 'invoices.*', 'payments.*', 'refunds.*', 'expenses.*'); @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $oActive ? 'active' : '' }}" href="#sidebarOrders" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $oActive ? 'true' : 'false' }}" aria-controls="sidebarOrders">
            <i class="ri-file-list-3-line"></i> <span>Orders</span>
        </a>
        <div class="collapse menu-dropdown {{ $oActive ? 'show' : '' }}" id="sidebarOrders">
            <ul class="nav nav-sm flex-column">
                @can('View order')<li class="nav-item"><a href="{{ route('orders.index') }}" class="nav-link {{ request()->routeIs('orders.*') ? 'active' : '' }}">All orders</a></li>@endcan
                @can('Manage quotation')<li class="nav-item"><a href="{{ route('quotations.index') }}" class="nav-link {{ request()->routeIs('quotations.*') ? 'active' : '' }}">Quotations</a></li>@endcan
                @can('View payment')
                <li class="nav-item"><a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}">Invoices</a></li>
                <li class="nav-item"><a href="{{ route('payments.index') }}" class="nav-link {{ request()->routeIs('payments.*', 'refunds.*') ? 'active' : '' }}">Payments</a></li>
                @endcan
                @if($u->can('Manage expense') || $u->can('View financial reports'))
                <li class="nav-item"><a href="{{ route('expenses.index') }}" class="nav-link {{ request()->routeIs('expenses.*') ? 'active' : '' }}">Expenses</a></li>
                @endif
            </ul>
        </div>
    </li>
    @endif

    @if($u->can('View online orders') || $u->can('Manage store products') || $u->can('Manage online store'))
    @php
        $stActive = request()->routeIs('store.*');
        $stNew = $u->can('View online orders') && ! $locked('online_store') ? \App\Models\ShopOrder::where(fn ($q) => $q->where('status', 'awaiting_confirmation')->orWhere('payment_status', 'pending'))->where('status', '!=', 'cancelled')->count() : 0;
    @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $stActive ? 'active' : '' }}" href="#sidebarStore" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $stActive ? 'true' : 'false' }}" aria-controls="sidebarStore">
            <i class="ri-store-3-line"></i> <span>Online Store</span>@if($locked('online_store'))<i class="ri-lock-line ms-1 small opacity-75" title="Not in your plan — open to see, upgrade to use"></i>@elseif($stNew)<span class="badge rounded-pill bg-danger ms-auto">{{ $stNew }}</span>@endif
        </a>
        <div class="collapse menu-dropdown {{ $stActive ? 'show' : '' }}" id="sidebarStore">
            <ul class="nav nav-sm flex-column">
                @can('View online orders')
                <li class="nav-item"><a href="{{ route('store.dashboard') }}" class="nav-link {{ request()->routeIs('store.dashboard') ? 'active' : '' }}">Overview</a></li>
                <li class="nav-item"><a href="{{ route('store.orders.index') }}" class="nav-link {{ request()->routeIs('store.orders.*') ? 'active' : '' }}">Orders @if($stNew)<span class="badge rounded-pill bg-danger ms-1">{{ $stNew }}</span>@endif</a></li>
                @endcan
                @can('Manage online orders')<li class="nav-item"><a href="{{ route('store.orders.create') }}" class="nav-link {{ request()->routeIs('store.orders.create') ? 'active' : '' }}">Take a WhatsApp order</a></li>@endcan
                @can('View product')<li class="nav-item"><a href="{{ route('store.products.index') }}" class="nav-link {{ request()->routeIs('store.products.*') ? 'active' : '' }}">Products & pictures</a></li>@endcan
                @can('Manage online store')
                <li class="nav-item"><a href="{{ route('store.coupons.index') }}" class="nav-link {{ request()->routeIs('store.coupons.*') ? 'active' : '' }}">Coupons</a></li>
                <li class="nav-item"><a href="{{ route('store.shipping.index') }}" class="nav-link {{ request()->routeIs('store.shipping.*') ? 'active' : '' }}">Delivery zones</a></li>
                <li class="nav-item"><a href="{{ route('store.banners.index') }}" class="nav-link {{ request()->routeIs('store.banners.*') ? 'active' : '' }}">Banners</a></li>
                @endcan
                @can('Moderate reviews')<li class="nav-item"><a href="{{ route('store.reviews.index') }}" class="nav-link {{ request()->routeIs('store.reviews.*') ? 'active' : '' }}">Reviews</a></li>@endcan
                @can('Manage online store')<li class="nav-item"><a href="{{ route('store.settings.edit') }}" class="nav-link {{ request()->routeIs('store.settings.*') ? 'active' : '' }}">Shop settings</a></li>@endcan
            </ul>
        </div>
    </li>
    @endif

    @can('View project')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('projects.*') ? 'active' : '' }}" href="{{ route('projects.index') }}">
            <i class="ri-heart-2-line"></i> <span>Weddings & Projects</span>@if($locked('projects'))<i class="ri-lock-line ms-1 small opacity-75" title="Not in your plan — open to see, upgrade to use"></i>@endif
        </a>
    </li>
    @endcan

    @can('View production')
    @php $prActive = request()->routeIs('production.*', 'fittings.*', 'deliveries.*'); @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $prActive ? 'active' : '' }}" href="#sidebarProduction" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $prActive ? 'true' : 'false' }}" aria-controls="sidebarProduction">
            <i class="ri-scissors-cut-line"></i> <span>Production</span>
        </a>
        <div class="collapse menu-dropdown {{ $prActive ? 'show' : '' }}" id="sidebarProduction">
            <ul class="nav nav-sm flex-column">
                <li class="nav-item"><a href="{{ route('production.board') }}" class="nav-link {{ request()->routeIs('production.board', 'production.tasks.*') ? 'active' : '' }}">Board</a></li>
                <li class="nav-item"><a href="{{ route('production.board', ['who' => 'mine']) }}" class="nav-link">My work</a></li>
                <li class="nav-item"><a href="{{ route('fittings.index') }}" class="nav-link {{ request()->routeIs('fittings.*') ? 'active' : '' }}">Fittings</a></li>
                @can('Manage production workflow')
                <li class="nav-item"><a href="{{ route('production.setup') }}" class="nav-link {{ request()->routeIs('production.setup', 'production.workflows.*') ? 'active' : '' }}">Workflows & checks</a></li>
                @endcan
            </ul>
        </div>
    </li>
    @endcan

    @can('Use POS')
    @php $pActive = request()->routeIs('pos.*'); @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $pActive ? 'active' : '' }}" href="#sidebarPos" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $pActive ? 'true' : 'false' }}" aria-controls="sidebarPos">
            <i class="ri-shopping-cart-2-line"></i> <span>Point of Sale</span>@if($locked('pos'))<i class="ri-lock-line ms-1 small opacity-75" title="Not in your plan — open to see, upgrade to use"></i>@endif
        </a>
        <div class="collapse menu-dropdown {{ $pActive ? 'show' : '' }}" id="sidebarPos">
            <ul class="nav nav-sm flex-column">
                <li class="nav-item"><a href="{{ route('pos.terminal') }}" class="nav-link {{ request()->routeIs('pos.terminal') ? 'active' : '' }}">Till</a></li>
                <li class="nav-item"><a href="{{ route('pos.sales.index') }}" class="nav-link {{ request()->routeIs('pos.sales.*') ? 'active' : '' }}">Sales</a></li>
                <li class="nav-item"><a href="{{ route('pos.shifts.index') }}" class="nav-link {{ request()->routeIs('pos.shifts.*') ? 'active' : '' }}">Shifts</a></li>
            </ul>
        </div>
    </li>
    @endcan

    @can('View inventory')
    @php $iActive = request()->routeIs('inventory.*', 'suppliers.*', 'purchase-orders.*', 'goods-receipts.*', 'stock-transfers.*', 'stock-adjustments.*', 'material-issues.*'); @endphp
    <li class="nav-item">
        <a class="nav-link menu-link collapsed {{ $iActive ? 'active' : '' }}" href="#sidebarInventory" data-bs-toggle="collapse" role="button"
           aria-expanded="{{ $iActive ? 'true' : 'false' }}" aria-controls="sidebarInventory">
            <i class="ri-archive-line"></i> <span>Inventory</span>@if($locked('inventory'))<i class="ri-lock-line ms-1 small opacity-75" title="Not in your plan — open to see, upgrade to use"></i>@endif
        </a>
        <div class="collapse menu-dropdown {{ $iActive ? 'show' : '' }}" id="sidebarInventory">
            <ul class="nav nav-sm flex-column">
                <li class="nav-item"><a href="{{ route('inventory.index') }}" class="nav-link {{ request()->routeIs('inventory.index', 'inventory.items.*') ? 'active' : '' }}">Stock</a></li>
                <li class="nav-item"><a href="{{ route('purchase-orders.index') }}" class="nav-link {{ request()->routeIs('purchase-orders.*', 'goods-receipts.*') ? 'active' : '' }}">Purchasing</a></li>
                <li class="nav-item"><a href="{{ route('material-issues.index') }}" class="nav-link {{ request()->routeIs('material-issues.*') ? 'active' : '' }}">Material issues</a></li>
                <li class="nav-item"><a href="{{ route('stock-adjustments.index') }}" class="nav-link {{ request()->routeIs('stock-adjustments.*', 'stock-transfers.*') ? 'active' : '' }}">Counts & adjustments</a></li>
                <li class="nav-item"><a href="{{ route('suppliers.index') }}" class="nav-link {{ request()->routeIs('suppliers.*') ? 'active' : '' }}">Suppliers</a></li>
            </ul>
        </div>
    </li>
    @endcan

    @can('View reports')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}">
            <i class="ri-bar-chart-box-line"></i> <span>Reports</span>
        </a>
    </li>
    @endcan

    @can('View customer materials')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('materials.*') ? 'active' : '' }}" href="{{ route('materials.index') }}">
            <i class="ri-archive-drawer-line"></i> <span>Customer materials</span>
        </a>
    </li>
    @endcan

    @can('View attendance')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('attendance.*') ? 'active' : '' }}" href="{{ route('attendance.index') }}">
            <i class="ri-qr-scan-2-line"></i> <span>Attendance</span>
        </a>
    </li>
    @endcan

    @if($visibleSoon->count())
        @foreach($visibleSoon as $m)
        <li class="nav-item">
            <a class="nav-link menu-link sidebar-soon" href="javascript:void(0)" title="Coming in a later phase" aria-disabled="true">
                <i class="{{ $m['icon'] }}"></i> <span>{{ $m['label'] }}</span>
                <span class="badge rounded-pill bg-secondary-subtle text-secondary ms-auto" style="font-size:9px;">Soon</span>
            </a>
        </li>
        @endforeach
    @endif

    {{-- USERS & ACCESS --}}
    @if($u->can('View user') || $u->can('View role') || $u->can('View audit log'))
        <li class="menu-title"><i class="ri-more-fill"></i> <span>Users &amp; Access</span></li>
    @endif

    @can('View user')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
            <i class="ph-user-circle"></i> <span>Staff &amp; Users</span>
        </a>
    </li>
    @endcan

    @canany(['View role', 'View permission'])
    <li class="nav-item">
        <a class="nav-link menu-link collapsed" href="#sidebarroles" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebarroles">
            <i class="ph-shield-check"></i> <span>Roles &amp; Permissions</span>
        </a>
        <div class="collapse menu-dropdown" id="sidebarroles">
            <ul class="nav nav-sm flex-column">
                @can('View role')
                    <li class="nav-item"><a href="{{ route('roles.index') }}" class="nav-link">Roles</a></li>
                @endcan
                @can('View permission')
                    <li class="nav-item"><a href="{{ route('permissions.index') }}" class="nav-link">Permissions</a></li>
                @endcan
            </ul>
        </div>
    </li>
    @endcanany

    @can('View audit log')
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('audit-logs.*') ? 'active' : '' }}" href="{{ route('audit-logs.index') }}">
            <i class="ri-shield-check-line"></i> <span>Audit Log</span>
        </a>
    </li>
    @endcan

    {{-- SETTINGS --}}
    @canany(['Manage tenant settings', 'View branch', 'Manage branch', 'Manage notification templates'])
        <li class="menu-title"><i class="ri-more-fill"></i> <span>Settings</span></li>
        @can('Manage notification templates')
        <li class="nav-item">
            <a class="nav-link menu-link {{ request()->routeIs('settings.customer-messages*') ? 'active' : '' }}" href="{{ route('settings.customer-messages') }}">
                <i class="ri-chat-settings-line"></i> <span>Customer Messages</span>
            </a>
        </li>
        @endcan
        @can('Manage tenant settings')
        <li class="nav-item">
            <a class="nav-link menu-link {{ request()->routeIs('settings.tenant.*') ? 'active' : '' }}" href="{{ route('settings.tenant.edit') }}">
                <i class="ri-store-2-line"></i> <span>House Settings</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link menu-link {{ request()->routeIs('settings.messaging*') ? 'active' : '' }}" href="{{ route('settings.messaging') }}">
                <i class="ri-message-3-line"></i> <span>Messaging</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link menu-link {{ request()->routeIs('website.*') ? 'active' : '' }}" href="{{ route('website.edit') }}">
                <i class="ri-global-line"></i> <span>Website</span>@if($locked('website'))<i class="ri-lock-line ms-1 small opacity-75"></i>@endif
            </a>
        </li>
        @if($ent->enabled())
        <li class="nav-item">
            <a class="nav-link menu-link {{ request()->routeIs('billing.*') ? 'active' : '' }}" href="{{ route('billing.index') }}">
                <i class="ri-vip-crown-line"></i> <span>Plan & billing</span>
                @if($p = $ent->plan($currentTenant))<span class="badge rounded-pill bg-secondary-subtle text-secondary ms-auto" style="font-size:9px;">{{ $p->name }}</span>@endif
            </a>
        </li>
        @endif
        @endcan
        @canany(['View branch', 'Manage branch'])
        <li class="nav-item">
            <a class="nav-link menu-link {{ request()->routeIs('settings.branches.*') ? 'active' : '' }}" href="{{ route('settings.branches.index') }}">
                <i class="ri-building-4-line"></i> <span>Branches &amp; Locations</span>
            </a>
        </li>
        @endcanany
    @endcanany
@endif

{{-- PLATFORM (SaaS operator) --}}
@if($u->is_platform_admin && config('tenancy.mode') !== 'single')
    <li class="menu-title"><i class="ri-more-fill"></i> <span>Platform</span></li>
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('platform.tenants.*') ? 'active' : '' }}" href="{{ route('platform.tenants.index') }}">
            <i class="ri-community-line"></i> <span>Fashion Houses</span>
        </a>
    </li>
    @if(config('billing.enabled'))
    <li class="nav-item">
        <a class="nav-link menu-link {{ request()->routeIs('platform.plans.*') ? 'active' : '' }}" href="{{ route('platform.plans.index') }}">
            <i class="ri-price-tag-3-line"></i> <span>Plans</span>
        </a>
    </li>
    @endif
@endif

{{-- MY ACCOUNT --}}
<li class="menu-title"><i class="ri-more-fill"></i> <span>My Account</span></li>
<li class="nav-item">
    <a class="nav-link menu-link collapsed" href="#sidebaraccount" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="sidebaraccount">
        <i class="ph-address-book"></i> <span>My Account</span>
    </a>
    <div class="collapse menu-dropdown" id="sidebaraccount">
        <ul class="nav nav-sm flex-column">
            @if($currentTenant && $u->can('View user'))
            <li class="nav-item">
                <a href="{{ route('users.show', $u->id) }}" class="nav-link"><i class="ri-profile-line me-2"></i> My Profile</a>
            </li>
            @endif
            <li class="nav-item">
                <a href="{{ route('profile.settings') }}" class="nav-link"><i class="ri-settings-3-line me-2"></i> Account Settings</a>
            </li>
            <li class="nav-item">
                <a href="{{ route('profile.settings', ['tab' => 'security']) }}" class="nav-link ps-4"><i class="ri-lock-password-line me-2"></i> Change Password</a>
            </li>
            @if($currentTenant)
            <li class="nav-item">
                <a href="{{ route('notifications.index') }}" class="nav-link ps-4"><i class="ri-notification-3-line me-2"></i> Notifications</a>
            </li>
            @endif
        </ul>
    </div>
</li>
