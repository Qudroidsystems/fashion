@extends('layouts.master')

@section('content')

{{-- ═══════════════════════════════════════════════════════════
     STYLES
═══════════════════════════════════════════════════════════ --}}
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap');

:root {
    --u-primary:    #1e3a5f;
    --u-accent:     #2563eb;
    --u-indigo:     #4f46e5;
    --u-success:    #16a34a;
    --u-warning:    #d97706;
    --u-danger:     #dc2626;
    --u-muted:      #6b7280;
    --u-border:     #e2e8f0;
    --u-bg:         #f8fafc;
    --u-surface:    #ffffff;
    --u-radius:     12px;
    --u-shadow:     0 2px 8px rgba(0,0,0,.07);
    --u-shadow-lg:  0 8px 32px rgba(0,0,0,.12);
}

*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Plus Jakarta Sans', sans-serif; }

/* ── Keyframes ── */
@keyframes fadeInUp   { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeInDown { from { opacity:0; transform:translateY(-14px);} to { opacity:1; transform:translateY(0); } }
@keyframes slideRight { from { opacity:0; transform:translateX(-20px);} to { opacity:1; transform:translateX(0); } }
@keyframes scaleIn    { from { opacity:0; transform:scale(.92);       } to { opacity:1; transform:scale(1);    } }
@keyframes pulse      { 0%,100%{transform:scale(1);}50%{transform:scale(1.05);} }
@keyframes shimmer    { from{background-position:-200% 0;} to{background-position:200% 0;} }
@keyframes rowIn      { from{opacity:0;transform:translateX(-8px);}to{opacity:1;transform:translateX(0);} }
@keyframes badgePop   { 0%{transform:scale(0.5);}70%{transform:scale(1.15);}100%{transform:scale(1);} }

/* ── Hero ── */
.u-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 55%, #4f46e5 100%);
    border-radius: var(--u-radius);
    padding: 28px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
    animation: fadeInDown .5s ease both;
}
.u-hero::before {
    content:''; position:absolute; top:-70px; right:-70px;
    width:240px; height:240px;
    background:rgba(255,255,255,.06); border-radius:50%;
    animation: pulse 6s ease-in-out infinite;
}
.u-hero::after {
    content:''; position:absolute; bottom:-50px; right:140px;
    width:150px; height:150px;
    background:rgba(255,255,255,.04); border-radius:50%;
}
.u-hero h1 { font-size:22px; font-weight:800; color:#fff; margin:0 0 6px; position:relative; letter-spacing:-.3px; }
.u-hero p  { font-size:13px; color:rgba(255,255,255,.75); margin:0; position:relative; }

/* ── Stat cards ── */
.stat-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    padding: 18px 20px;
    transition: transform .2s, box-shadow .2s;
    animation: fadeInUp .5s ease both;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content:''; position:absolute; bottom:0; left:0; right:0; height:3px;
    border-radius:0 0 var(--u-radius) var(--u-radius);
    background: linear-gradient(90deg, var(--u-accent), var(--u-indigo));
    transform: scaleX(0); transform-origin: left;
    transition: transform .3s ease;
}
.stat-card:hover { transform:translateY(-3px); box-shadow:var(--u-shadow-lg); }
.stat-card:hover::before { transform: scaleX(1); }
.stat-card .stat-value { font-size:28px; font-weight:800; color:var(--u-primary); line-height:1; }
.stat-card .stat-label { font-size:12px; color:var(--u-muted); margin-top:5px; font-weight:500; }
.stat-card .stat-icon  { font-size:34px; opacity:.1; float:right; margin-top:-6px; }

/* ── Filter area ── */
.u-filter-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-top: 3px solid var(--u-accent);
    border-radius: var(--u-radius);
    padding: 16px 20px;
    animation: fadeInUp .5s .1s ease both;
}
.u-input {
    border: 1.5px solid var(--u-border);
    border-radius: 9px;
    padding: 9px 14px;
    font-size: 13px;
    font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
    width: 100%;
    background: #fff;
}
.u-input:focus { border-color:var(--u-accent); outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.u-input-icon-wrap { position:relative; }
.u-input-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; pointer-events:none; }
.u-input-icon-wrap .u-input { padding-left: 34px; }

/* ── Table card ── */
.u-table-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    overflow: hidden;
    box-shadow: var(--u-shadow);
    animation: fadeInUp .5s .15s ease both;
}
.u-table-card .card-header {
    background: var(--u-surface);
    border-bottom: 1px solid var(--u-border);
    padding: 14px 20px;
}
.u-table thead th {
    background: var(--u-primary);
    color: #fff;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
    border: none;
}
.u-table tbody td {
    padding: 11px 16px;
    vertical-align: middle;
    border-bottom: 1px solid var(--u-border);
    font-size: 13px;
    transition: background .12s;
}
.u-table tbody tr {
    animation: rowIn .3s ease both;
}
.u-table tbody tr:hover td { background: #f0f9ff; }
.u-table tbody tr:last-child td { border-bottom: none; }

/* ── Avatar ── */
.u-avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff;
    border: 2px solid var(--u-border);
    flex-shrink: 0;
    transition: transform .2s;
}
.u-avatar:hover { transform: scale(1.1); }

/* ── Role badges ── */
.u-role-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
    animation: badgePop .3s ease;
}
.u-role-pill          { background:color-mix(in srgb, var(--pill, #64748b) 12%, #fff); color:var(--pill, #475569); border:1px solid color-mix(in srgb, var(--pill, #64748b) 30%, #fff); }
.u-role-pill.default  { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
.u-status-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.u-status-pill.active    { background:#dcfce7; color:#166534; }
.u-status-pill.invited   { background:#e0f2fe; color:#075985; }
.u-status-pill.suspended { background:#fee2e2; color:#991b1b; }
.u-status-pill.disabled  { background:#f1f5f9; color:#475569; }

/* ── Action buttons ── */
.u-action-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    border: none; cursor: pointer;
    font-size: 13px;
    transition: transform .15s, box-shadow .15s;
    text-decoration: none;
}
.u-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.15); }
.u-action-btn.view   { background:#eff6ff; color:#2563eb; }
.u-action-btn.edit   { background:#f0fdf4; color:#16a34a; }
.u-action-btn.key    { background:#fffbeb; color:#d97706; }
.u-action-btn.del    { background:#fef2f2; color:#dc2626; }

/* ── Top buttons ── */
.u-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 9px;
    font-size: 13px; font-weight: 600;
    border: none; cursor: pointer;
    transition: transform .15s, box-shadow .15s, opacity .15s;
    text-decoration: none;
}
.u-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.15); opacity: .92; }
.u-btn.primary  { background:linear-gradient(135deg,var(--u-accent),var(--u-indigo)); color:#fff; }
.u-btn.success  { background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; }
.u-btn.warning  { background:linear-gradient(135deg,#d97706,#b45309); color:#fff; }
.u-btn.danger   { background:var(--u-danger); color:#fff; }
.u-btn.ghost    { background:#fff; color:var(--u-primary); border:1.5px solid var(--u-border); }

/* ── Modal redesign ── */
.u-modal .modal-content {
    border: none;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: var(--u-shadow-lg);
    animation: scaleIn .25s ease;
}
.u-modal-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 60%, #4f46e5 100%);
    padding: 22px 28px;
    position: relative; overflow: hidden;
}
.u-modal-hero::before {
    content:''; position:absolute; top:-40px; right:-40px;
    width:140px; height:140px;
    background:rgba(255,255,255,.07); border-radius:50%;
}
.u-modal-hero h5 { color:#fff; font-weight:700; font-size:16px; margin:0; position:relative; }
.u-modal-hero p  { color:rgba(255,255,255,.72); font-size:12px; margin:4px 0 0; position:relative; }
.u-modal-hero .btn-close { position:absolute; top:18px; right:20px; filter:invert(1); opacity:.8; }
.u-modal-body { padding: 22px 24px; background: var(--u-bg); }
.u-modal-footer { padding: 14px 24px; background: var(--u-surface); border-top:1px solid var(--u-border); }

.u-form-label {
    font-size: 11.5px; font-weight: 700; color: var(--u-muted);
    text-transform: uppercase; letter-spacing: .4px;
    display: block; margin-bottom: 5px;
}
.u-form-input {
    width: 100%;
    border: 1.5px solid var(--u-border);
    border-radius: 9px;
    padding: 10px 14px;
    font-size: 13px;
    font-family: inherit;
    background: #fff;
    transition: border-color .2s, box-shadow .2s;
}
.u-form-input:focus { border-color: var(--u-accent); outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.1); }

/* ── Empty state ── */
.u-empty {
    text-align:center; padding:48px 24px; color: var(--u-muted);
}
.u-empty i { font-size:3rem; display:block; margin-bottom:12px; opacity:.3; }

/* ── Loading shimmer ── */
.shimmer {
    background: linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 6px;
    height: 16px;
}

/* ── Chart card ── */
.u-chart-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    overflow: hidden;
    box-shadow: var(--u-shadow);
    animation: fadeInUp .5s .05s ease both;
    margin-bottom: 24px;
}
.u-chart-card .chart-header {
    padding: 14px 20px;
    background: var(--u-surface);
    border-bottom: 1px solid var(--u-border);
    font-weight: 700; font-size: 14px; color: var(--u-primary);
    display: flex; align-items: center; gap: 8px;
}

/* ── Stagger animation for rows ── */
.u-table tbody tr:nth-child(1)  { animation-delay: .03s; }
.u-table tbody tr:nth-child(2)  { animation-delay: .06s; }
.u-table tbody tr:nth-child(3)  { animation-delay: .09s; }
.u-table tbody tr:nth-child(4)  { animation-delay: .12s; }
.u-table tbody tr:nth-child(5)  { animation-delay: .15s; }
.u-table tbody tr:nth-child(6)  { animation-delay: .18s; }
.u-table tbody tr:nth-child(7)  { animation-delay: .21s; }
.u-table tbody tr:nth-child(8)  { animation-delay: .24s; }
.u-table tbody tr:nth-child(9)  { animation-delay: .27s; }
.u-table tbody tr:nth-child(10) { animation-delay: .30s; }

/* ── Pagination ── */
.u-pagination {
    display: flex; align-items: center; gap: 4px; flex-wrap: wrap;
}
.u-page-btn {
    min-width: 32px; height: 32px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 7px; border: 1.5px solid var(--u-border);
    background: #fff; color: var(--u-primary);
    font-size: 12px; font-weight: 600; cursor: pointer;
    transition: all .15s;
    text-decoration: none;
}
.u-page-btn:hover, .u-page-btn.active { background: var(--u-accent); color:#fff; border-color:var(--u-accent); }

</style>

@php
    $roleBadges = $roles->pluck('badge', 'name');
    $isOwner = auth()->user()->hasRole(\App\Support\PermissionCatalog::OWNER_ROLE);
@endphp

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    {{-- Breadcrumb --}}
    <div class="row mb-1" style="animation:fadeInDown .4s ease both;">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb m-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}" style="color:var(--u-accent)">{{ $brand['name'] }}</a></li>
                    <li class="breadcrumb-item active text-muted">Staff &amp; Users</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Hero --}}
    <div class="u-hero">
        <div class="row align-items-center">
            <div class="col">
                <h1><i class="ri-team-line me-2"></i>Staff &amp; Users</h1>
                <p>Everyone who works in {{ $brand['name'] }}: their roles, branch and access.</p>
            </div>
            <div class="col-auto d-none d-md-flex gap-2">
                @can('View role')
                <a href="{{ route('roles.index') }}" class="u-btn ghost"><i class="bi bi-shield-lock"></i> Roles</a>
                @endcan
                @can('Create user')
                <button type="button" class="u-btn primary" id="openAddUserModalBtn">
                    <i class="bi bi-plus-circle"></i> Add User
                </button>
                @endcan
            </div>
        </div>
    </div>

    {{-- Alerts --}}
    @if ($errors->any())
    <div class="alert alert-danger border-0 rounded-3 mb-3" style="animation:fadeInDown .4s ease both;">
        <strong>Input errors:</strong> {{ $errors->all()[0] }}
    </div>
    @endif
    @if (session('success'))
    <div class="alert alert-success border-0 rounded-3 mb-3" style="animation:fadeInDown .4s ease both;">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    </div>
    @endif

    {{-- Stat Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="ri-group-line"></i></div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="ri-user-follow-line"></i></div>
                <div class="stat-value" style="color:var(--u-success)">{{ $stats['active'] }}</div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="ri-user-unfollow-line"></i></div>
                <div class="stat-value" style="color:var(--u-danger)">{{ $stats['suspended'] }}</div>
                <div class="stat-label">Suspended</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="ri-vip-crown-line"></i></div>
                <div class="stat-value" style="color:var(--u-accent)">{{ $stats['owners'] }}</div>
                <div class="stat-label">Owners</div>
            </div>
        </div>
    </div>

    {{-- Chart --}}
    <div class="u-chart-card">
        <div class="chart-header">
            <i class="ri-bar-chart-2-line" style="color:var(--u-accent)"></i>
            Users by Role
        </div>
        <div class="p-4">
            <canvas id="usersByRoleChart" height="80"></canvas>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="u-filter-card mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="u-form-label">Search</label>
                <div class="u-input-icon-wrap">
                    <i class="bi bi-search u-input-icon"></i>
                    <input type="text" id="liveSearch" class="u-input" placeholder="Name, email, phone…">
                </div>
            </div>
            <div class="col-md-3">
                <label class="u-form-label">Role</label>
                <select id="roleFilter" class="u-input">
                    <option value="">All Roles</option>
                    @foreach ($roles as $role)
                    <option value="{{ strtolower($role->name) }}">{{ $role->name }}</option>
                    @endforeach
                    <option value="no role">No Role</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="u-form-label">Branch</label>
                <select id="branchFilter" class="u-input">
                    <option value="">All Branches</option>
                    @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" class="u-btn ghost w-100" id="clearFilters">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="u-table-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="fw-bold" style="color:var(--u-primary);font-size:14px;">
                <i class="ri-list-check me-2" style="color:var(--u-accent)"></i>
                Users
                <span id="userCountBadge" class="badge ms-2" style="background:var(--u-accent);font-size:11px;font-weight:600;">{{ $data->count() }}</span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                @can('Delete user')
                <button class="u-btn danger d-none" id="remove-actions" onclick="deleteMultiple()">
                    <i class="ri-delete-bin-2-line"></i> Remove Selected
                </button>
                @endcan
                @can('Create user')
                <div class="d-md-none d-flex gap-2">
                    <button type="button" class="u-btn primary" id="openAddUserModalBtnMobile">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                </div>
                @endcan
            </div>
        </div>

        <div class="table-responsive">
            <table class="table u-table mb-0" id="usersTable">
                <thead>
                    <tr>
                        <th width="40">
                            <input type="checkbox" id="checkAll" style="accent-color:#fff;cursor:pointer;">
                        </th>
                        <th width="50"></th>
                        <th class="sortable" data-key="name">Name <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th class="sortable" data-key="email">Contact <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th>Role</th>
                        <th class="sortable" data-key="branch">Branch <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th>Status</th>
                        <th class="sortable" data-key="date">Joined <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th width="130">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    @forelse ($data as $key => $user)
                    @php
                        $m = $user->memberships->first();
                        $roleNames = $user->getRoleNames();
                        $avatarColors = ['#667eea','#f093fb','#4facfe','#43e97b','#fa709a','#30cfd0'];
                        $avatarColor = $avatarColors[$key % count($avatarColors)];
                        $status = $user->is_disabled ? 'disabled' : ($m?->status ?? 'active');
                    @endphp
                    <tr data-id="{{ $user->id }}"
                        data-name="{{ strtolower($user->name) }}"
                        data-email="{{ strtolower($user->email) }}"
                        data-phone="{{ $user->phone_number }}"
                        data-roles="{{ strtolower($roleNames->implode(',')) }}"
                        data-branch-id="{{ $m?->branch_id }}"
                        data-branch="{{ strtolower($m?->branch?->name ?? '') }}"
                        data-date="{{ ($m?->joined_at ?? $user->created_at)->format('Y-m-d') }}">
                        <td>
                            @if($user->id !== auth()->id())
                            <input type="checkbox" class="row-check" name="chk_child" style="accent-color:var(--u-accent);cursor:pointer;">
                            @endif
                        </td>
                        <td>
                            @if($user->hasCustomAvatar())
                                <img src="{{ $user->avatar_url }}" alt="" class="u-avatar" style="object-fit:cover;">
                            @else
                            <div class="u-avatar" style="background:linear-gradient(135deg,{{ $avatarColor }} 0%,{{ $avatarColors[($key+2)%count($avatarColors)] }} 100%)">
                                {{ $user->initials ?: 'U' }}
                            </div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold" style="color:var(--u-primary)">
                                <a href="{{ route('users.show', $user->id) }}" style="color:inherit;text-decoration:none;">
                                    {{ $user->name }}
                                </a>
                                @if($m?->is_owner)<i class="ri-vip-crown-fill ms-1 text-warning" title="Owner"></i>@endif
                            </div>
                            @if($m?->job_title)<div class="small text-muted">{{ $m->job_title }}</div>@endif
                        </td>
                        <td>
                            <span class="text-muted small d-block">{{ $user->email }}</span>
                            @if($user->phone_number)<span class="text-muted small">{{ $user->phone_number }}</span>@endif
                        </td>
                        <td>
                            @forelse($roleNames as $role)
                                <span class="u-role-pill" style="--pill: {{ $roleBadges[$role] ?? '#64748b' }}">
                                    <i class="bi bi-shield-check"></i>{{ $role }}
                                </span>
                            @empty
                                <span class="u-role-pill default"><i class="bi bi-dash"></i>No Role</span>
                            @endforelse
                        </td>
                        <td class="small">{{ $m?->branch?->name ?? '—' }}</td>
                        <td><span class="u-status-pill {{ $status }}">{{ ucfirst($status) }}</span></td>
                        <td class="text-muted small">{{ ($m?->joined_at ?? $user->created_at)->format('d M Y') }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('users.show', $user->id) }}" class="u-action-btn view" title="View">
                                    <i class="ph-eye"></i>
                                </a>
                                @can('Update user')
                                <button type="button" class="u-action-btn edit edit-item-btn" title="Edit"
                                    data-id="{{ $user->id }}"
                                    data-name="{{ $user->name }}"
                                    data-email="{{ $user->email }}"
                                    data-phone="{{ $user->phone_number }}"
                                    data-job-title="{{ $m?->job_title }}"
                                    data-branch-id="{{ $m?->branch_id }}"
                                    data-status="{{ $m?->status ?? 'active' }}"
                                    data-roles="{{ $roleNames->implode(',') }}">
                                    <i class="ph-pencil"></i>
                                </button>
                                @endcan
                                @can('Delete user')
                                @if($user->id !== auth()->id())
                                <button type="button" class="u-action-btn del remove-item-btn" title="Remove"
                                    data-id="{{ $user->id }}">
                                    <i class="ph-trash"></i>
                                </button>
                                @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr id="emptyRow">
                        <td colspan="9">
                            <div class="u-empty">
                                <i class="ri-user-line"></i>
                                No users found
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Count --}}
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top"
             style="background:var(--u-bg);">
            <div class="text-muted small">
                Showing <span id="showingCount" class="fw-semibold text-dark">{{ $data->count() }}</span>
                of <span id="totalCount" class="fw-semibold text-dark">{{ $data->count() }}</span> users
            </div>
            <div id="paginationEl" class="u-pagination"></div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════
         ADD USER MODAL
    ══════════════════════════════════════════════════════ --}}
    @can('Create user')
    <div id="showModal" class="modal fade u-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="u-modal-hero">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <h5><i class="bi bi-person-plus me-2"></i>Add New User</h5>
                    <p>Create a staff account, or add someone who already has an account by their e-mail.</p>
                </div>
                <form id="add-user-form" autocomplete="off">
                    <div class="u-modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="u-form-label">Full Name</label>
                                <input type="text" id="name" name="name" class="u-form-input" placeholder="e.g. Amaka Okafor" required>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Email Address</label>
                                <input type="email" id="email" name="email" class="u-form-input" placeholder="staff@yourhouse.com" required>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Phone <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" id="phone_number" name="phone_number" class="u-form-input" placeholder="+234…">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Job Title <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" id="job_title" name="job_title" class="u-form-input" placeholder="e.g. Head Tailor">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Role(s)</label>
                                <select id="role" name="roles[]" class="u-form-input" multiple required size="5">
                                    @foreach ($roles as $role)
                                        @if($role->name !== \App\Support\PermissionCatalog::OWNER_ROLE || $isOwner)
                                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <div class="small text-muted mt-1">Hold Ctrl/Cmd to select multiple</div>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Branch</label>
                                <select id="branch_id" name="branch_id" class="u-form-input">
                                    <option value="">— No specific branch —</option>
                                    @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Password</label>
                                <input type="password" id="password" name="password" class="u-form-input" placeholder="Min. 8 chars">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Confirm Password</label>
                                <input type="password" id="password_confirmation" name="password_confirmation" class="u-form-input" placeholder="Repeat password">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="must_change_password" checked>
                                    <label class="form-check-label small" for="must_change_password">Ask them to choose their own password at first sign-in</label>
                                </div>
                                <div class="small text-muted mt-1">If the e-mail already has an account (e.g. they work with another fashion house), leave the password blank — they keep their own.</div>
                            </div>
                        </div>
                        <div class="alert alert-danger d-none mt-3 rounded-3" id="add-alert"></div>
                    </div>
                    <div class="u-modal-footer d-flex justify-content-end gap-2">
                        <button type="button" class="u-btn ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="u-btn primary" id="add-btn">
                            <i class="bi bi-plus-circle"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan

    {{-- ══════════════════════════════════════════════════════
         EDIT USER MODAL
    ══════════════════════════════════════════════════════ --}}
    @can('Update user')
    <div id="editModal" class="modal fade u-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="u-modal-hero" style="background:linear-gradient(135deg,#065f46,#16a34a,#4ade80);">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <h5><i class="ph-pencil me-2"></i>Edit User</h5>
                    <p>Update details, branch, status and roles</p>
                </div>
                <form id="edit-user-form" autocomplete="off">
                    <div class="u-modal-body">
                        <input type="hidden" id="edit-id-field">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="u-form-label">Full Name</label>
                                <input type="text" id="edit-name" name="name" class="u-form-input" required>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Email Address</label>
                                <input type="email" id="edit-email" name="email" class="u-form-input" required>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Phone</label>
                                <input type="text" id="edit-phone" name="phone_number" class="u-form-input">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Job Title</label>
                                <input type="text" id="edit-job-title" name="job_title" class="u-form-input">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Role(s)</label>
                                <select id="edit-role" name="roles[]" class="u-form-input" multiple required size="5">
                                    @foreach ($roles as $role)
                                    <option value="{{ $role->name }}" @if($role->name === \App\Support\PermissionCatalog::OWNER_ROLE && ! $isOwner) disabled @endif>{{ $role->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Branch</label>
                                <select id="edit-branch" name="branch_id" class="u-form-input mb-3">
                                    <option value="">— No specific branch —</option>
                                    @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                                <label class="u-form-label">Status</label>
                                <select id="edit-status" name="status" class="u-form-input">
                                    <option value="active">Active</option>
                                    <option value="suspended">Suspended (cannot sign in to this house)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">New Password <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="password" id="edit-password" name="password" class="u-form-input" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Confirm Password</label>
                                <input type="password" id="edit-password_confirmation" name="password_confirmation" class="u-form-input" placeholder="Repeat if changing">
                            </div>
                        </div>
                        <div class="alert alert-danger d-none mt-3 rounded-3" id="edit-alert"></div>
                    </div>
                    <div class="u-modal-footer d-flex justify-content-end gap-2">
                        <button type="button" class="u-btn ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="u-btn success" id="update-btn">
                            <i class="bi bi-check-circle"></i> Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan

    {{-- ══════════════════════════════════════════════════════
         DELETE CONFIRM MODAL
    ══════════════════════════════════════════════════════ --}}
    <div id="deleteRecordModal" class="modal fade u-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
            <div class="modal-content">
                <div class="modal-body p-5 text-center">
                    <div style="width:70px;height:70px;border-radius:50%;background:#fef2f2;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
                        <i class="bi bi-trash" style="font-size:28px;color:var(--u-danger)"></i>
                    </div>
                    <h4 class="fw-700" style="color:var(--u-primary)">Remove user?</h4>
                    <p class="text-muted mb-4">They lose access to {{ $brand['name'] }}. If they don't work with any other fashion house, their account is deleted.</p>
                    <div class="d-flex gap-3 justify-content-center">
                        <button type="button" class="u-btn ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="u-btn danger" id="delete-record">
                            <i class="bi bi-trash"></i> Yes, Remove
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</div>
</div>

{{-- ══════════════════════════════════════════════════════════════
     SCRIPTS (jQuery + Bootstrap come from the master layout)
══════════════════════════════════════════════════════════════ --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/axios@1.7.9/dist/axios.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap not loaded!');
        return;
    }

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const URLS = {
        store: @json(route('users.store')),
        item: id => @json(url('/users')) + '/' + id,
    };
    const ROLE_BADGES = @json($roleBadges);
    const BRANCHES = @json($branches->pluck('name', 'id'));
    axios.defaults.headers.common['X-CSRF-TOKEN'] = CSRF;
    axios.defaults.headers.common['Accept'] = 'application/json';

    function showModal(modalId) {
        const el = document.getElementById(modalId);
        if (!el) return null;
        let modal = bootstrap.Modal.getInstance(el);
        if (!modal) modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: true });
        modal.show();
        return modal;
    }

    function hideModal(modalId) {
        const el = document.getElementById(modalId);
        if (el) bootstrap.Modal.getInstance(el)?.hide();
    }

    ['openAddUserModalBtn', 'openAddUserModalBtnMobile'].forEach(btnId => {
        document.getElementById(btnId)?.addEventListener('click', e => { e.preventDefault(); showModal('showModal'); });
    });

    // ── Table filtering ─────────────────────────────────────
    const allRows = () => Array.from(document.querySelectorAll('#usersTableBody tr[data-id]'));

    function applyFilters() {
        const search = document.getElementById('liveSearch')?.value.toLowerCase().trim() || '';
        const role   = document.getElementById('roleFilter')?.value.toLowerCase().trim() || '';
        const branch = document.getElementById('branchFilter')?.value || '';
        let shown = 0;
        allRows().forEach(row => {
            const d = row.dataset;
            const matchSearch = !search || (d.name || '').includes(search) || (d.email || '').includes(search) || (d.phone || '').includes(search);
            const roles = d.roles || '';
            const matchRole = !role || roles.split(',').some(r => r.trim() === role) || (role === 'no role' && !roles.trim());
            const matchBranch = !branch || d.branchId === branch;
            const visible = matchSearch && matchRole && matchBranch;
            row.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        document.getElementById('showingCount').textContent = shown;
        document.getElementById('userCountBadge').textContent = shown;

        let empty = document.getElementById('noResults');
        if (shown === 0 && allRows().length > 0) {
            if (!empty) {
                empty = document.createElement('tr');
                empty.id = 'noResults';
                empty.innerHTML = `<td colspan="9"><div class="u-empty"><i class="ri-search-line"></i>No users match your filters</div></td>`;
                document.getElementById('usersTableBody')?.appendChild(empty);
            }
        } else if (empty) empty.remove();
    }

    document.getElementById('liveSearch')?.addEventListener('input', applyFilters);
    document.getElementById('roleFilter')?.addEventListener('change', applyFilters);
    document.getElementById('branchFilter')?.addEventListener('change', applyFilters);
    document.getElementById('clearFilters')?.addEventListener('click', () => {
        ['liveSearch', 'roleFilter', 'branchFilter'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        applyFilters();
    });

    // Sortable columns
    const sortDir = {};
    document.querySelectorAll('.sortable').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const key = th.dataset.key;
            sortDir[key] = !sortDir[key];
            const tbody = document.getElementById('usersTableBody');
            allRows()
                .sort((a, b) => sortDir[key] ? (a.dataset[key] || '').localeCompare(b.dataset[key] || '') : (b.dataset[key] || '').localeCompare(a.dataset[key] || ''))
                .forEach(r => tbody.appendChild(r));
        });
    });

    // Check all
    document.getElementById('checkAll')?.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(cb => {
            if (cb.closest('tr')?.style.display !== 'none') cb.checked = this.checked;
        });
        updateRemoveBtn();
    });
    document.addEventListener('change', e => { if (e.target.classList.contains('row-check')) updateRemoveBtn(); });
    function updateRemoveBtn() {
        const count = document.querySelectorAll('.row-check:checked').length;
        document.getElementById('remove-actions')?.classList.toggle('d-none', count === 0);
    }

    function removeRow(id) {
        document.querySelector(`tr[data-id="${id}"]`)?.remove();
        const totalEl = document.getElementById('totalCount');
        if (totalEl) totalEl.textContent = Math.max(0, parseInt(totalEl.textContent) - 1);
    }

    // Delete single
    document.addEventListener('click', e => {
        const btn = e.target.closest('.remove-item-btn');
        if (!btn) return;
        const id = btn.dataset.id;
        const deleteBtn = document.getElementById('delete-record');
        const fresh = deleteBtn.cloneNode(true);
        deleteBtn.replaceWith(fresh);
        fresh.addEventListener('click', () => {
            axios.delete(URLS.item(id))
                .then(res => {
                    removeRow(id);
                    hideModal('deleteRecordModal');
                    Swal.fire({ icon: 'success', title: 'Done', text: res.data.message, showConfirmButton: false, timer: 2200 });
                    applyFilters();
                })
                .catch(err => Swal.fire('Not removed', err.response?.data?.message || 'Delete failed', 'error'));
        });
        showModal('deleteRecordModal');
    });

    // Delete multiple
    window.deleteMultiple = function () {
        const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.closest('tr').dataset.id).filter(Boolean);
        if (!ids.length) { Swal.fire('Select at least one user'); return; }
        Swal.fire({
            title: 'Remove ' + ids.length + ' user(s)?',
            text: 'They lose access to this fashion house.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Yes, Remove'
        }).then(r => {
            if (!r.isConfirmed) return;
            Promise.allSettled(ids.map(id => axios.delete(URLS.item(id)).then(() => id)))
                .then(results => {
                    const ok = results.filter(x => x.status === 'fulfilled').map(x => x.value);
                    const failed = results.filter(x => x.status === 'rejected').map(x => x.reason?.response?.data?.message).filter(Boolean);
                    ok.forEach(removeRow);
                    Swal.fire(ok.length + ' removed', failed.length ? failed.join('<br>') : '', failed.length ? 'warning' : 'success');
                    updateRemoveBtn();
                    applyFilters();
                });
        });
    };

    // Edit user
    document.addEventListener('click', e => {
        const btn = e.target.closest('.edit-item-btn');
        if (!btn) return;
        const d = btn.dataset;
        document.getElementById('edit-id-field').value = d.id;
        document.getElementById('edit-name').value = d.name || '';
        document.getElementById('edit-email').value = d.email || '';
        document.getElementById('edit-phone').value = d.phone || '';
        document.getElementById('edit-job-title').value = d.jobTitle || '';
        document.getElementById('edit-branch').value = d.branchId || '';
        document.getElementById('edit-status').value = d.status || 'active';
        const roles = (d.roles || '').split(',');
        Array.from(document.getElementById('edit-role').options).forEach(opt => { opt.selected = roles.includes(opt.value); });
        document.getElementById('edit-password').value = '';
        document.getElementById('edit-password_confirmation').value = '';
        document.getElementById('edit-alert')?.classList.add('d-none');
        showModal('editModal');
    });

    function firstError(err, fallback) {
        const data = err.response?.data || {};
        if (data.errors) return Object.values(data.errors).flat()[0];
        return data.message || fallback;
    }

    // Add user
    document.getElementById('add-user-form')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const alert = document.getElementById('add-alert');
        alert?.classList.add('d-none');
        const val = id => document.getElementById(id)?.value.trim() || '';
        const roles = Array.from(document.getElementById('role')?.selectedOptions || []).map(o => o.value);
        const pass = document.getElementById('password').value;
        const conf = document.getElementById('password_confirmation').value;
        if (!val('name')) return showAlert(alert, 'Enter a name');
        if (!val('email')) return showAlert(alert, 'Enter an email');
        if (!roles.length) return showAlert(alert, 'Select at least one role');
        if (pass !== conf) return showAlert(alert, 'Passwords do not match');
        const btn = document.getElementById('add-btn');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating…';
        const payload = {
            name: val('name'), email: val('email'), phone_number: val('phone_number') || null,
            job_title: val('job_title') || null, branch_id: val('branch_id') || null, roles,
            must_change_password: document.getElementById('must_change_password').checked,
        };
        if (pass) { payload.password = pass; payload.password_confirmation = conf; }
        axios.post(URLS.store, payload)
            .then(res => {
                addRowToTable(res.data.user);
                hideModal('showModal');
                this.reset();
                Swal.fire({ icon: 'success', title: 'User added', text: res.data.message, showConfirmButton: false, timer: 2800 });
            })
            .catch(err => showAlert(alert, firstError(err, 'Error creating user')))
            .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-circle"></i> Create User'; });
    });

    // Edit user
    document.getElementById('edit-user-form')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const alert = document.getElementById('edit-alert');
        alert?.classList.add('d-none');
        const val = id => document.getElementById(id)?.value.trim() || '';
        const id = val('edit-id-field');
        const roles = Array.from(document.getElementById('edit-role')?.selectedOptions || []).map(o => o.value);
        const pass = document.getElementById('edit-password').value;
        const conf = document.getElementById('edit-password_confirmation').value;
        if (!val('edit-name')) return showAlert(alert, 'Enter a name');
        if (!val('edit-email')) return showAlert(alert, 'Enter an email');
        if (!roles.length) return showAlert(alert, 'Select at least one role');
        if (pass && pass !== conf) return showAlert(alert, 'Passwords do not match');
        const btn = document.getElementById('update-btn');
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
        const payload = {
            name: val('edit-name'), email: val('edit-email'), phone_number: val('edit-phone') || null,
            job_title: val('edit-job-title') || null, branch_id: val('edit-branch') || null,
            status: val('edit-status'), roles,
        };
        if (pass) { payload.password = pass; payload.password_confirmation = conf; }
        axios.put(URLS.item(id), payload)
            .then(res => {
                updateRowInTable(res.data.user);
                hideModal('editModal');
                Swal.fire({ icon: 'success', title: 'Updated', text: res.data.message, showConfirmButton: false, timer: 2800 });
            })
            .catch(err => showAlert(alert, firstError(err, 'Error updating user')))
            .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Update User'; });
    });

    function showAlert(el, msg) { if (el) { el.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + escHtml(msg); el.classList.remove('d-none'); setTimeout(() => el.classList.add('d-none'), 6000); } }
    function rolePill(roleName) {
        return `<span class="u-role-pill" style="--pill:${ROLE_BADGES[roleName] || '#64748b'}"><i class="bi bi-shield-check"></i>${escHtml(roleName)}</span>`;
    }
    function statusPill(s) { s = s || 'active'; return `<span class="u-status-pill ${s}">${s.charAt(0).toUpperCase() + s.slice(1)}</span>`; }
    function escHtml(s) { return String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])); }
    function getInitials(name) { const parts = (name || '').split(' ').filter(Boolean); return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase(); }
    function contactCell(u) { return `<span class="text-muted small d-block">${escHtml(u.email)}</span>${u.phone_number ? `<span class="text-muted small">${escHtml(u.phone_number)}</span>` : ''}`; }
    function nameCell(u) { return `<div class="fw-semibold" style="color:var(--u-primary)"><a href="${URLS.item(u.id)}" style="color:inherit;text-decoration:none;">${escHtml(u.name)}</a></div>${u.job_title ? `<div class="small text-muted">${escHtml(u.job_title)}</div>` : ''}`; }
    function setRowData(row, u) {
        Object.assign(row.dataset, {
            id: u.id, name: u.name.toLowerCase(), email: u.email.toLowerCase(), phone: u.phone_number || '',
            roles: (u.roles || []).join(',').toLowerCase(), branchId: u.branch_id ? String(u.branch_id) : '',
            branch: (u.branch || '').toLowerCase(),
        });
    }
    function editButton(u) {
        return `<button type="button" class="u-action-btn edit edit-item-btn" title="Edit" data-id="${u.id}" data-name="${escHtml(u.name)}" data-email="${escHtml(u.email)}" data-phone="${escHtml(u.phone_number || '')}" data-job-title="${escHtml(u.job_title || '')}" data-branch-id="${u.branch_id || ''}" data-status="${u.status || 'active'}" data-roles="${escHtml((u.roles || []).join(','))}"><i class="ph-pencil"></i></button>`;
    }
    function addRowToTable(u) {
        const tbody = document.getElementById('usersTableBody');
        document.getElementById('emptyRow')?.remove();
        const row = document.createElement('tr');
        setRowData(row, u);
        row.dataset.date = new Date().toISOString().slice(0, 10);
        row.innerHTML = `
            <td><input type="checkbox" class="row-check" style="accent-color:var(--u-accent);cursor:pointer;"></td>
            <td><div class="u-avatar">${escHtml(getInitials(u.name))}</div></td>
            <td>${nameCell(u)}</td>
            <td>${contactCell(u)}</td>
            <td>${(u.roles || []).map(rolePill).join('')}</td>
            <td class="small">${escHtml(u.branch || '—')}</td>
            <td>${statusPill(u.status)}</td>
            <td class="text-muted small">${new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
            <td><div class="d-flex gap-1">
                <a href="${URLS.item(u.id)}" class="u-action-btn view" title="View"><i class="ph-eye"></i></a>
                ${editButton(u)}
                <button type="button" class="u-action-btn del remove-item-btn" title="Remove" data-id="${u.id}"><i class="ph-trash"></i></button>
            </div></td>`;
        tbody.prepend(row);
        const total = document.getElementById('totalCount');
        if (total) total.textContent = parseInt(total.textContent) + 1;
        applyFilters();
    }
    function updateRowInTable(u) {
        const row = document.querySelector(`tr[data-id="${u.id}"]`);
        if (!row) return;
        setRowData(row, u);
        row.cells[2].innerHTML = nameCell(u);
        row.cells[3].innerHTML = contactCell(u);
        row.cells[4].innerHTML = (u.roles || []).map(rolePill).join('');
        row.cells[5].textContent = u.branch || '—';
        row.cells[6].innerHTML = statusPill(u.status);
        row.querySelector('.edit-item-btn')?.replaceWith(Object.assign(document.createElement('template'), { innerHTML: editButton(u) }).content.firstChild);
        applyFilters();
    }

    // Chart
    const ctx = document.getElementById('usersByRoleChart')?.getContext('2d');
    if (ctx && window.Chart) {
        const labels = @json(array_keys($role_counts));
        new Chart(ctx, {
            type: 'bar',
            data: { labels, datasets: [{ label: 'Users', data: @json(array_values($role_counts)), backgroundColor: labels.map(l => (ROLE_BADGES[l] || '#94a3b8') + 'cc'), borderRadius: 8 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });
    }
});
</script>
@endsection
