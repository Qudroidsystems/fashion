@extends('measurements.public.layout', ['house' => null])

@section('title', 'Complete your account')
@section('foot', 'You can change all of this later in House Settings.')

@section('body')
    <div class="panel">
        <h1 class="h4 fw-bold mb-1">Complete your account</h1>
        <p class="text-muted mb-0">You are signed in as {{ $user->email }}. Tell us about your fashion house and we’ll set it up with a {{ config('tenancy.trial_days') }}-day free trial.</p>
    </div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    @if($canCreate)
    <form method="POST" action="{{ route('onboarding.store') }}" class="panel">
        <div class="field"><label for="name" class="form-label">Your name</label>
            <input id="name" name="name" class="form-control form-control-lg" required maxlength="255" value="{{ old('name', $user->name) }}"></div>
        <div class="field"><label for="business_name" class="form-label">Fashion house name</label>
            <input id="business_name" name="business_name" class="form-control form-control-lg" required maxlength="120" value="{{ old('business_name') }}" placeholder="e.g. Adunni Couture" autofocus></div>
        <div class="field"><label for="phone_number" class="form-label">Phone number</label>
            <input id="phone_number" name="phone_number" class="form-control form-control-lg" required maxlength="30" value="{{ old('phone_number', $user->phone_number) }}" placeholder="0803 123 4567" inputmode="tel"></div>
        <div class="field"><label for="city" class="form-label">City <span class="text-muted fw-normal">(optional)</span></label>
            <input id="city" name="city" class="form-control" maxlength="120" value="{{ old('city') }}" placeholder="e.g. Lekki, Lagos"></div>
        <div class="field row g-2">
            <div class="col-6"><label for="currency_code" class="form-label">Currency</label>
                <select id="currency_code" name="currency_code" class="form-select">@foreach($currencies as $k => $l)<option value="{{ $k }}" @selected(old('currency_code', 'NGN') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-6"><label for="timezone" class="form-label">Time zone</label>
                <select id="timezone" name="timezone" class="form-select">
                    @foreach(['Africa/Lagos', 'Africa/Accra', 'Africa/Nairobi', 'Africa/Johannesburg', 'Europe/London', 'America/New_York', 'UTC'] as $tz)<option value="{{ $tz }}" @selected(old('timezone', 'Africa/Lagos') === $tz)>{{ $tz }}</option>@endforeach
                </select></div>
        </div>
        <button class="btn btn-accent btn-lg w-100 mt-3">Create my fashion house</button>
        @csrf
    </form>
    @else
        <div class="panel">Your account isn’t part of a fashion house yet. Ask the owner of your fashion house to invite you with {{ $user->email }}.</div>
    @endif
    <form method="POST" action="{{ route('logout') }}" class="text-center">@csrf<button class="btn btn-link text-muted">Sign out</button></form>
@endsection
