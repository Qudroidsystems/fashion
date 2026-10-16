@php $u = auth()->user(); @endphp
<ul class="nav nav-pills gap-1 mb-3 flex-nowrap overflow-auto pb-1">
    @can('View production')
    <li class="nav-item"><a href="{{ route('production.board') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('production.board', 'production.tasks.*') ? 'active' : '' }}"><i class="ri-layout-column-line me-1"></i>Board</a></li>
    @endcan
    @if($u->can('View production') || $u->can('Manage fitting'))
    <li class="nav-item"><a href="{{ route('fittings.index') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('fittings.*') ? 'active' : '' }}"><i class="ri-calendar-check-line me-1"></i>Fittings</a></li>
    @endif
    @can('Manage production workflow')
    <li class="nav-item"><a href="{{ route('production.setup') }}" class="nav-link text-nowrap py-1 px-3 {{ request()->routeIs('production.setup', 'production.workflows.*') ? 'active' : '' }}"><i class="ri-settings-4-line me-1"></i>Setup</a></li>
    @endcan
</ul>
