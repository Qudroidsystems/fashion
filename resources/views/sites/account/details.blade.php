@extends('sites.account._layout')

@section('account')
    <div class="account-details">
        <form method="POST" action="{{ $base }}/account" class="store-form">
            @csrf @method('PUT')
            <h6 class="mb-3">Your details</h6>
            <div class="grid-2">
                <input type="text" name="first_name" value="{{ old('first_name', $account->first_name) }}" placeholder="First name" required maxlength="80" aria-label="First name">
                <input type="text" name="last_name" value="{{ old('last_name', $account->last_name) }}" placeholder="Last name" maxlength="80" aria-label="Last name">
            </div>
            <div class="grid-2">
                <input type="email" name="email" value="{{ old('email', $account->email) }}" placeholder="E-mail" required aria-label="E-mail">
                <input type="tel" name="phone" value="{{ old('phone', $account->phone) }}" placeholder="Phone / WhatsApp" maxlength="30" aria-label="Phone">
            </div>
            <label class="d-flex gap-2 text-caption-1"><input type="checkbox" name="marketing_opt_in" value="1" class="tf-check" @checked($account->marketing_opt_in)> Tell me about new pieces and offers</label>
            <h6 class="mt-4 mb-3">Change password <small class="text-secondary">(optional)</small></h6>
            <input type="password" name="current_password" placeholder="Current password" autocomplete="current-password" aria-label="Current password">
            <div class="grid-2">
                <input type="password" name="password" placeholder="New password" autocomplete="new-password" aria-label="New password">
                <input type="password" name="password_confirmation" placeholder="Confirm new password" autocomplete="new-password" aria-label="Confirm new password">
            </div>
            @if($errors->any())<div class="store-err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
            <button class="tf-btn btn-fill"><span class="text">Save</span></button>
        </form>
    </div>
@endsection
