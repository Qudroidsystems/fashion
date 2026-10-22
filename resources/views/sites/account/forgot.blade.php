@extends('sites.layout')
@section('title', 'Reset your password')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Reset your password'])
    <section class="flat-spacing">
        <div class="container" style="max-width:560px">
            <p class="text-secondary">Enter your e-mail and we will send you a link to choose a new password.</p>
            <form method="POST" action="{{ $base }}/account/forgot" class="store-form">
                @csrf
                <input type="email" name="email" value="{{ old('email') }}" placeholder="E-mail address" required aria-label="E-mail">
                @error('email')<div class="store-err">{{ $message }}</div>@enderror
                <button class="tf-btn btn-fill w-100"><span class="text">Send the link</span></button>
            </form>
            <p class="mt-3"><a href="{{ $base }}/account/login" class="link">Back to sign in</a></p>
        </div>
    </section>
@endsection
