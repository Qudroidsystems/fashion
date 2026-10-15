@extends('measurements.public.layout')
@section('title', 'Link unavailable')
@section('body')
@php
    $messages = [
        'invalid'   => ['ri-link-unlink', 'This link is not valid', 'Please check you opened the full link, or ask the fashion house for a new one.'],
        'expired'   => ['ri-time-line', 'This link has expired', 'Ask the fashion house to send you a new link.'],
        'submitted' => ['ri-checkbox-circle-line', 'Measurements already sent', 'Thank you — we have already received your measurements from this link.'],
        'revoked'   => ['ri-close-circle-line', 'This link was cancelled', 'Please contact the fashion house if you still need to send measurements.'],
        'locked'    => ['ri-lock-line', 'This link is locked', 'There were too many unsuccessful attempts. Please ask the fashion house for a new link.'],
    ];
    [$icon, $title, $text] = $messages[$reason] ?? $messages['invalid'];
@endphp
<div class="panel text-center py-5">
    <i class="{{ $icon }}" style="font-size:48px;color:var(--accent)"></i>
    <h4 class="mt-3">{{ $title }}</h4>
    <p class="text-muted mb-0">{{ $text }}</p>
</div>
@endsection
