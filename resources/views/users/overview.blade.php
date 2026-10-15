@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <!-- Page Title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">User Overview - {{ $user->name }}</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Staff &amp; Users</a></li>
                                <li class="breadcrumb-item active">Overview</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xxl-12">

                    <!-- Tabs & Navigation -->
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-4">
                        <ul class="nav nav-pills arrow-navtabs nav-secondary gap-2 flex-grow-1" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#personalDetails">
                                    <i class="ri-user-line me-1"></i> Profile &amp; Details
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#accessInfo">
                                    <i class="ri-shield-keyhole-line me-1"></i> Roles &amp; Access
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#activityTimeline">
                                    <i class="ri-history-line me-1"></i> Activity Timeline
                                </a>
                            </li>
                        </ul>
                        <div class="flex-shrink-0 ms-auto">
                            <a href="{{ route('users.index') }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Users
                            </a>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="tab-content">

                                <!-- Personal Details -->
                                <div class="tab-pane active" id="personalDetails" role="tabpanel">
                                    <div class="text-center mb-5">
                                        <div class="position-relative d-inline-block">
                                            <div class="avatar-xxl mx-auto">
                                                @if($user->hasCustomAvatar())
                                                    <img src="{{ $user->avatar_url }}" alt="Profile"
                                                         class="rounded-circle img-thumbnail"
                                                         style="width: 150px; height: 150px; object-fit: cover;">
                                                @else
                                                    <div class="avatar-title rounded-circle bg-light text-primary fs-1"
                                                         style="width: 150px; height: 150px; line-height: 150px;">
                                                        {{ $user->initials }}
                                                    </div>
                                                @endif
                                            </div>
                                            <h4 class="mt-3 mb-1">
                                                {{ $user->name }}
                                                @if($membership->is_owner)<i class="ri-vip-crown-fill text-warning" title="Owner"></i>@endif
                                            </h4>
                                            <p class="text-muted mb-0">{{ $membership->job_title ?? $user->email }}</p>
                                            <div class="mt-2">
                                                @foreach($user->roles as $role)
                                                    <span class="badge me-1" style="background:{{ $role->badge ?? '#299cdb' }}">{{ $role->name }}</span>
                                                @endforeach
                                                @php $status = $user->is_disabled ? 'disabled' : $membership->status; @endphp
                                                <span class="badge {{ $status === 'active' ? 'bg-success' : 'bg-danger' }}">{{ ucfirst($status) }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12"><h5 class="mb-3 border-bottom pb-2">Contact</h5></div>
                                        <div class="col-md-6">
                                            <label>Full Name</label>
                                            <input type="text" class="form-control" value="{{ $user->name }}" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Email</label>
                                            <input type="text" class="form-control" value="{{ $user->email }}" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Phone Number</label>
                                            <input type="text" class="form-control" value="{{ $user->phone_number ?? 'N/A' }}" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Gender</label>
                                            <input type="text" class="form-control" value="{{ ucfirst($user->gender ?? 'N/A') }}" readonly>
                                        </div>

                                        <div class="col-12 mt-4"><h5 class="mb-3 border-bottom pb-2">In {{ $brand['name'] }}</h5></div>
                                        <div class="col-md-6">
                                            <label>Job Title</label>
                                            <input type="text" class="form-control" value="{{ $membership->job_title ?? 'N/A' }}" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Branch</label>
                                            <input type="text" class="form-control" value="{{ $membership->branch?->name ?? 'All branches' }}" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Joined</label>
                                            <input type="text" class="form-control" value="{{ ($membership->joined_at ?? $membership->created_at)?->format('d M Y') }}" readonly>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Last Sign-in</label>
                                            <input type="text" class="form-control" value="{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}" readonly>
                                        </div>
                                    </div>
                                </div>

                                <!-- Roles & Access -->
                                <div class="tab-pane" id="accessInfo" role="tabpanel">
                                    <h5 class="mb-3">What {{ $user->first_name }} can do</h5>
                                    @forelse($permissions as $group => $names)
                                        <div class="mb-3">
                                            <div class="fw-semibold small text-uppercase text-muted mb-2">{{ $group }}</div>
                                            @foreach($names as $p)
                                                @php $meta = \App\Support\PermissionMeta::for($p); @endphp
                                                <span class="badge bg-light text-body border me-1 mb-1" title="{{ $meta['desc'] }}">{{ $p }}</span>
                                            @endforeach
                                        </div>
                                    @empty
                                        <div class="text-center py-5 text-muted">
                                            <i class="ri-lock-line fs-1"></i>
                                            <h5 class="mt-3">No permissions</h5>
                                            <p>This user has no role with permissions in {{ $brand['name'] }} yet.</p>
                                        </div>
                                    @endforelse
                                </div>

                                <!-- Activity Timeline -->
                                <div class="tab-pane" id="activityTimeline" role="tabpanel">
                                    <h5 class="mb-4">Account Activity Timeline</h5>
                                    <div class="timeline">
                                        @foreach($activity as $log)
                                        <div class="timeline-item">
                                            <div class="timeline-date">{{ $log->created_at->timezone($currentTenant?->timezone ?? config('app.timezone'))->format('d M Y, H:i') }}</div>
                                            <div class="timeline-content">
                                                <h6>{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $log->action)) }}</h6>
                                                <p class="text-muted mb-0">
                                                    {{ $log->description }}
                                                    @if($log->user && $log->user_id !== $user->id) · by {{ $log->user->name }} @endif
                                                </p>
                                            </div>
                                        </div>
                                        @endforeach
                                        <div class="timeline-item">
                                            <div class="timeline-date">{{ $user->created_at->format('d M Y') }}</div>
                                            <div class="timeline-content">
                                                <h6>Account Created</h6>
                                                <p class="text-muted mb-0">User account was registered on the platform</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline { position: relative; padding-left: 40px; }
.timeline::before { content: ''; position: absolute; left: 18px; top: 0; bottom: 0; width: 4px; background: #e9ecef; }
.timeline-item { position: relative; margin-bottom: 30px; }
.timeline-item::before {
    content: ''; position: absolute; left: -24px; top: 8px; width: 12px; height: 12px; border-radius: 50%;
    background: #4e73df; border: 3px solid #fff; box-shadow: 0 0 0 4px #e3e8ff;
}
.timeline-date { font-size: 0.85rem; color: #6c757d; margin-bottom: 5px; }
.timeline-content h6 { margin: 0 0 5px 0; color: #495057; }
.avatar-xxl { width: 150px; height: 150px; }
.avatar-xxl img { width: 100%; height: 100%; object-fit: cover; }
.border-bottom { border-bottom: 2px solid var(--bs-border-color) !important; }
</style>
@endsection
