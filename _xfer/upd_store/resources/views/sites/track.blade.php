@extends('sites.layout')
@section('title', 'Track your order')

@section('body')
    @include('sites.partials.page-title', ['title' => 'Track your order'])
    <section class="flat-spacing">
        <div class="container" style="max-width:560px">
            <p class="text-secondary mb-4">Enter your order number (it starts with WEB-) and the phone number or e-mail you used.</p>
            <form method="POST" action="{{ $base }}/track" class="store-form">
                @csrf
                <input type="text" name="number" value="{{ old('number') }}" placeholder="Order number, e.g. WEB-{{ now()->year }}-00012" required maxlength="40" aria-label="Order number">
                <input type="text" name="contact" value="{{ old('contact') }}" placeholder="Phone or e-mail" required maxlength="160" aria-label="Phone or e-mail">
                @error('number')<div class="store-err">{{ $message }}</div>@enderror
                <button class="tf-btn btn-fill w-100"><span class="text">Find my order</span></button>
            </form>
            @if($account)<p class="text-caption-1 mt-3">Signed in? <a href="{{ $base }}/account/orders" class="link">See all your orders</a>.</p>@endif
        </div>
    </section>
@endsection
