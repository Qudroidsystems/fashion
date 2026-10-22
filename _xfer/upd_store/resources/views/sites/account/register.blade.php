@extends('sites.layout')
@section('title', 'Create an account')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Create an account'])
    <section class="flat-spacing">
        <div class="container">
            <div class="login-wrap">
                <div class="left">
                    <div class="heading"><h4>Create an account</h4></div>
                    <form method="POST" action="{{ $base }}/account/register" class="form-login form-has-password">
                        @csrf
                        <input type="hidden" name="next" value="{{ request('next') }}">
                        <div class="wrap">
                            <div class="grid-2">
                                <fieldset><input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="First name *" required maxlength="80" autocomplete="given-name" aria-label="First name"></fieldset>
                                <fieldset><input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="Last name" maxlength="80" autocomplete="family-name" aria-label="Last name"></fieldset>
                            </div>
                            <fieldset><input type="email" name="email" value="{{ old('email') }}" placeholder="E-mail address *" required autocomplete="email" aria-label="E-mail"></fieldset>
                            <fieldset><input type="tel" name="phone" value="{{ old('phone') }}" placeholder="Phone / WhatsApp" maxlength="30" autocomplete="tel" aria-label="Phone"></fieldset>
                            <fieldset class="position-relative password-item"><input class="input-password" type="password" name="password" placeholder="Password (8+ characters) *" required autocomplete="new-password" aria-label="Password"><span class="toggle-password unshow"><i class="icon-eye-hide-line"></i></span></fieldset>
                            <fieldset class="position-relative password-item"><input class="input-password" type="password" name="password_confirmation" placeholder="Confirm password *" required autocomplete="new-password" aria-label="Confirm password"><span class="toggle-password unshow"><i class="icon-eye-hide-line"></i></span></fieldset>
                            <input type="text" name="website" class="d-none" tabindex="-1" autocomplete="off" aria-hidden="true">
                            <label class="d-flex gap-2 text-caption-1"><input type="checkbox" name="marketing_opt_in" value="1" class="tf-check" @checked(old('marketing_opt_in'))> Tell me about new pieces and offers</label>
                            @if($errors->any())<div class="store-err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
                        </div>
                        <div class="button-submit"><button class="tf-btn btn-fill" type="submit"><span class="text text-button">Create account</span></button></div>
                    </form>
                </div>
                <div class="right">
                    <h4 class="mb_8">Already have an account?</h4>
                    <p class="text-secondary">Sign in to see your orders and check out faster.</p>
                    <a href="{{ $base }}/account/login{{ request('next') ? '?next=' . request('next') : '' }}" class="tf-btn btn-fill"><span class="text text-button">Sign in</span></a>
                </div>
            </div>
        </div>
    </section>
@endsection
