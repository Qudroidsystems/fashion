@extends('sites.layout')
@section('title', 'Sign in')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Sign in'])
    <section class="flat-spacing">
        <div class="container">
            <div class="login-wrap">
                <div class="left">
                    <div class="heading"><h4>Sign in</h4></div>
                    @include('sites.partials.google-button', ['label' => 'Sign in with Google'])
                    <form method="POST" action="{{ $base }}/account/login" class="form-login form-has-password">
                        @csrf
                        <input type="hidden" name="next" value="{{ request('next') }}">
                        <div class="wrap">
                            <fieldset><input type="email" placeholder="E-mail address *" name="email" value="{{ old('email') }}" required autocomplete="email" aria-label="E-mail"></fieldset>
                            <fieldset class="position-relative password-item">
                                <input class="input-password" type="password" placeholder="Password *" name="password" required autocomplete="current-password" aria-label="Password">
                                <span class="toggle-password unshow"><i class="icon-eye-hide-line"></i></span>
                            </fieldset>
                            @error('email')<div class="store-err">{{ $message }}</div>@enderror
                            <div class="d-flex justify-content-end"><a href="{{ $base }}/account/forgot" class="font-2 text-button forget-password link">Forgot your password?</a></div>
                        </div>
                        <div class="button-submit"><button class="tf-btn btn-fill" type="submit"><span class="text text-button">Sign in</span></button></div>
                    </form>
                </div>
                <div class="right">
                    <h4 class="mb_8">New here?</h4>
                    <p class="text-secondary">Create an account to check out faster, keep your addresses and wishlist, and follow all your orders in one place.</p>
                    <a href="{{ $base }}/account/register{{ request('next') ? '?next=' . request('next') : '' }}" class="tf-btn btn-fill"><span class="text text-button">Create an account</span></a>
                    @if($shop['guest_checkout'] && request('next') === 'checkout')<p class="mt-3"><a href="{{ $base }}/checkout" class="link">Or check out as a guest</a></p>@endif
                </div>
            </div>
        </div>
    </section>
@endsection
