<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account unavailable | {{ config('app.name') }}</title>
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .box { max-width: 480px; width: 100%; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(15,35,66,.12); padding: 36px 28px; text-align: center; }
        .ico { width: 72px; height: 72px; border-radius: 50%; background: #fef3c7; color: #b45309; display: inline-flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="ico"><i class="ri-store-3-line"></i></div>
        <h4 class="fw-bold mb-2">{{ $tenant->name }} is not available</h4>
        @php
            $why = match ($tenant->status) {
                'trial'     => 'The free trial has ended.',
                'past_due'  => 'The subscription payment is overdue and the grace period has ended.',
                'suspended' => 'This account has been suspended.',
                'cancelled' => 'This account has been cancelled.',
                default     => 'This account is not active.',
            };
        @endphp
        <p class="text-muted">{{ $why }} Please contact the owner of {{ $tenant->name }}{{ config('tenancy.mode') !== 'single' ? ' or platform support' : '' }}.</p>
        <div class="d-flex gap-2 justify-content-center mt-4">
            @if(config('tenancy.mode') !== 'single' && auth()->user()?->memberships()->active()->count() > 1)
                <a href="{{ route('profile.settings', ['tab' => 'houses']) }}" class="btn btn-primary">Switch fashion house</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-light">Sign out</button></form>
        </div>
    </div>
</body>
</html>
