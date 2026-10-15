@extends('measurements.public.layout')
@section('title', 'Thank you')
@section('body')
<div class="panel text-center py-5">
    <i class="ri-checkbox-circle-fill" style="font-size:56px;color:var(--accent)"></i>
    <h4 class="mt-3">Thank you, {{ $req->customer?->first_name }}!</h4>
    <p class="text-muted mb-0">{{ $house->name }} has received your measurements and will review them before using them for your outfit. They may contact you if anything needs checking.</p>
</div>
@endsection
