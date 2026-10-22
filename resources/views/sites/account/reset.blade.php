@extends('sites.layout')
@section('title', 'Choose a new password')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Choose a new password'])
    <section class="flat-spacing">
        <div class="container" style="max-width:560px">
            <form method="POST" action="{{ $base }}/account/reset/{{ $token }}" class="store-form">
                @csrf
                <input type="password" name="password" placeholder="New password (8+ characters)" required autocomplete="new-password" aria-label="New password">
                <input type="password" name="password_confirmation" placeholder="Confirm new password" required autocomplete="new-password" aria-label="Confirm password">
                @error('password')<div class="store-err">{{ $message }}</div>@enderror
                <button class="tf-btn btn-fill w-100"><span class="text">Save password</span></button>
            </form>
        </div>
    </section>
@endsection
