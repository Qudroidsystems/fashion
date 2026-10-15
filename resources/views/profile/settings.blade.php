@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            @if($user->must_change_password)
                <div class="alert alert-warning alert-border-left border-warning mb-4" role="alert">
                    <i class="ri-lock-password-line me-2"></i>
                    <strong>Choose your own password</strong><br>
                    You signed in with a temporary password. Set a new one under <em>Security</em> to continue.
                </div>
            @endif

            <!-- Page Title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">{{ $user->name }} - Profile Settings</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">Profile Settings</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="ri-check-line me-2"></i> {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('warning'))
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="ri-error-warning-line me-2"></i> {{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="ri-error-warning-line me-2"></i>
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="row">
                <div class="col-xxl-12">

                    <!-- Tabs Navigation -->
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-4">
                        <ul class="nav nav-pills arrow-navtabs nav-secondary gap-2 flex-grow-1" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link {{ $tab === 'profile' ? 'active' : '' }}" data-bs-toggle="tab" href="#personalDetails">
                                    <i class="ri-user-line me-1"></i> Personal Details
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link {{ $tab === 'security' ? 'active' : '' }}" data-bs-toggle="tab" href="#security">
                                    <i class="ri-shield-keyhole-line me-1"></i> Security
                                </a>
                            </li>
                            @if($memberships->count())
                            <li class="nav-item">
                                <a class="nav-link {{ $tab === 'houses' ? 'active' : '' }}" data-bs-toggle="tab" href="#houses">
                                    <i class="ri-store-2-line me-1"></i> My Fashion Houses
                                </a>
                            </li>
                            @endif
                        </ul>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="tab-content">

                                <!-- Personal Details Tab -->
                                <div class="tab-pane {{ $tab === 'profile' ? 'active' : '' }}" id="personalDetails" role="tabpanel">
                                    <div class="text-center mb-5">
                                        <div class="position-relative d-inline-block">
                                            <div class="avatar-xxl">
                                                @if($user->hasCustomAvatar())
                                                    <img src="{{ $user->avatar_url }}" alt="Profile"
                                                         class="rounded-circle img-thumbnail" id="profilePreview"
                                                         style="width: 150px; height: 150px; object-fit: cover;">
                                                @else
                                                    <div class="avatar-title rounded-circle bg-light text-primary fs-1"
                                                         style="width: 150px; height: 150px; line-height: 150px;"
                                                         id="profilePreview">
                                                        {{ $user->initials }}
                                                    </div>
                                                @endif
                                            </div>
                                            <label for="avatar" class="position-absolute bottom-0 end-0 btn btn-sm btn-icon btn-primary rounded-circle" title="Change photo">
                                                <i class="ri-camera-line fs-16"></i>
                                                <input type="file" id="avatar" name="avatar" class="d-none" accept="image/png,image/jpeg,image/webp">
                                            </label>
                                        </div>
                                        <h4 class="mt-3 mb-1">{{ $user->name }}</h4>
                                        <p class="text-muted mb-0">{{ $user->email }}</p>
                                        <div class="mt-2">
                                            @foreach($user->roles as $role)
                                                <span class="badge me-1" style="background:{{ $role->badge ?? '#299cdb' }}">{{ $role->name }}</span>
                                            @endforeach
                                        </div>
                                    </div>

                                    <form action="{{ route('profile.update-info') }}" method="POST">
                                        @csrf
                                        <div class="row g-3">
                                            <div class="col-12"><h5 class="mb-3 border-bottom pb-2">Personal Information</h5></div>
                                            <div class="col-md-6">
                                                <label>Full Name <span class="text-danger">*</span></label>
                                                <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Username <span class="text-muted">(optional, for sign-in)</span></label>
                                                <input type="text" name="username" class="form-control" value="{{ old('username', $user->username) }}">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Phone Number</label>
                                                <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number', $user->phone_number) }}">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Gender</label>
                                                <select name="gender" class="form-control">
                                                    <option value="">Select</option>
                                                    @foreach(['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $value => $label)
                                                        <option value="{{ $value }}" @selected(old('gender', $user->gender) === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12 text-end mt-4">
                                                <button type="reset" class="btn btn-light me-2">Reset</button>
                                                <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i> Save Changes</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Security Tab -->
                                <div class="tab-pane {{ $tab === 'security' ? 'active' : '' }}" id="security" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-6 mb-4">
                                            <div class="card border">
                                                <div class="card-header bg-light"><h5 class="card-title mb-0"><i class="ri-mail-line me-2"></i>Update Email</h5></div>
                                                <div class="card-body">
                                                    <form id="updateEmailForm">
                                                        @csrf
                                                        <div class="mb-3"><label>Current Email</label><input type="email" class="form-control" value="{{ $user->email }}" readonly></div>
                                                        <div class="mb-3"><label>New Email *</label><input type="email" name="email" class="form-control" required></div>
                                                        <div class="mb-3"><label>Confirm New Email *</label><input type="email" name="email_confirmation" class="form-control" required></div>
                                                        <div class="mb-3"><label>Current Password *</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
                                                        <button type="submit" class="btn btn-primary"><span class="spinner-border spinner-border-sm d-none me-1"></span> Update Email</button>
                                                    </form>
                                                    <div id="emailMessage" class="mt-3"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-4">
                                            <div class="card border">
                                                <div class="card-header bg-light"><h5 class="card-title mb-0"><i class="ri-lock-line me-2"></i>Change Password</h5></div>
                                                <div class="card-body">
                                                    <form id="updatePasswordForm">
                                                        @csrf
                                                        <div class="mb-3"><label>Current Password *</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
                                                        <div class="mb-3"><label>New Password *</label><input type="password" name="password" class="form-control" required autocomplete="new-password"></div>
                                                        <div class="mb-3"><label>Confirm Password *</label><input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password"></div>
                                                        <div class="alert alert-info"><i class="ri-information-line me-2"></i> Minimum 8 characters</div>
                                                        <button type="submit" class="btn btn-primary"><span class="spinner-border spinner-border-sm d-none me-1"></span> Update Password</button>
                                                    </form>
                                                    <div id="passwordMessage" class="mt-3"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Fashion houses -->
                                @if($memberships->count())
                                <div class="tab-pane {{ $tab === 'houses' ? 'active' : '' }}" id="houses" role="tabpanel">
                                    <h5 class="mb-3">Fashion houses you work with</h5>
                                    <div class="table-responsive">
                                        <table class="table align-middle">
                                            <thead class="table-light"><tr><th>Fashion house</th><th>Your roles</th><th>Branch</th><th>Status</th><th></th></tr></thead>
                                            <tbody>
                                            @foreach($memberships as $m)
                                                <tr>
                                                    <td class="fw-semibold">{{ $m->tenant->name }} @if($m->is_owner)<i class="ri-vip-crown-fill text-warning"></i>@endif</td>
                                                    <td>{{ $m->role_names ?: '—' }}</td>
                                                    <td>{{ $m->branch?->name ?? 'All branches' }}</td>
                                                    <td><span class="badge {{ $m->status === 'active' ? 'bg-success' : 'bg-secondary' }}">{{ ucfirst($m->status) }}</span></td>
                                                    <td class="text-end">
                                                        @if($m->status === 'active' && $m->tenant_id !== $currentTenant?->id && config('tenancy.mode') !== 'single')
                                                        <form method="POST" action="{{ route('tenant.switch') }}">
                                                            @csrf
                                                            <input type="hidden" name="tenant" value="{{ $m->tenant->public_id }}">
                                                            <button class="btn btn-sm btn-soft-primary">Switch to</button>
                                                        </form>
                                                        @elseif($m->tenant_id === $currentTenant?->id)
                                                            <span class="text-muted small">Current</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                @endif

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-xxl { width: 150px; height: 150px; }
.avatar-xxl img { width: 100%; height: 100%; object-fit: cover; }
.border-bottom { border-bottom: 2px solid var(--bs-border-color) !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const CSRF = @json(csrf_token());

    function postForm(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body
        }).then(async r => ({ ok: r.ok, data: await r.json().catch(() => ({})) }));
    }

    function firstError(data, fallback) {
        if (data.errors) return Object.values(data.errors).flat()[0];
        return data.message || fallback;
    }

    function showMessage(el, ok, msg) {
        const span = document.createElement('span');
        span.textContent = msg;
        el.innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} alert-dismissible fade show mt-3">
            <i class="ri-${ok ? 'check' : 'error-warning'}-line me-2"></i>${span.innerHTML}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    }

    // Avatar upload
    document.getElementById('avatar')?.addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) { Swal.fire('Error', 'Image must be less than 5MB', 'error'); this.value = ''; return; }
        if (!file.type.match('image.*')) { Swal.fire('Error', 'Please select an image file', 'error'); this.value = ''; return; }

        const fd = new FormData();
        fd.append('avatar', file);
        Swal.fire({ title: 'Uploading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        postForm(@json(route('profile.update-avatar')), fd).then(({ ok, data }) => {
            Swal.close();
            if (!ok) return Swal.fire('Error!', firstError(data, 'Failed to upload photo'), 'error');
            const preview = document.getElementById('profilePreview');
            const img = document.createElement('img');
            img.src = data.avatar_url + '?t=' + Date.now();
            img.alt = 'Profile';
            img.className = 'rounded-circle img-thumbnail';
            img.id = 'profilePreview';
            img.style.cssText = 'width:150px;height:150px;object-fit:cover;';
            preview.replaceWith(img);
            Swal.fire({ icon: 'success', title: 'Photo updated', timer: 1800, showConfirmButton: false });
        }).catch(() => { Swal.close(); Swal.fire('Error!', 'Network error. Please try again.', 'error'); });
    });

    function wire(formId, url, msgId, onOk) {
        const form = document.getElementById(formId);
        form?.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const spinner = btn.querySelector('.spinner-border');
            btn.disabled = true; spinner?.classList.remove('d-none');
            postForm(url, new FormData(form)).then(({ ok, data }) => {
                showMessage(document.getElementById(msgId), ok, ok ? data.message : firstError(data, 'Something went wrong'));
                if (ok) { form.reset(); onOk && onOk(data); }
            }).catch(() => showMessage(document.getElementById(msgId), false, 'Request failed. Please try again.'))
              .finally(() => { btn.disabled = false; spinner?.classList.add('d-none'); });
        });
    }

    wire('updateEmailForm', @json(route('profile.update-email')), 'emailMessage');
    wire('updatePasswordForm', @json(route('profile.update-password')), 'passwordMessage', data => {
        if (data.redirect) setTimeout(() => window.location.href = data.redirect, 1200);
    });
})();
</script>
@endsection
