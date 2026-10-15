@extends('measurements.public.layout')
@section('title', 'Payment')
@section('body')
@php use App\Support\Money; @endphp
<div class="panel text-center py-5">
    @if(! $intent)
        <i class="ri-error-warning-line" style="font-size:48px;color:var(--accent)"></i>
        <h4 class="mt-3">We couldn’t find this payment</h4>
        <p class="text-muted mb-0">If money left your account, please contact the fashion house with your bank or card receipt.</p>
    @elseif($intent->status === 'succeeded')
        <i class="ri-checkbox-circle-fill" style="font-size:56px;color:var(--accent)"></i>
        <h4 class="mt-3">Thank you{{ $intent->customer ? ', ' . $intent->customer->first_name : '' }}!</h4>
        <p class="mb-1">We received <strong>{{ Money::format($intent->amount, $intent->currency) }}</strong>{{ $intent->order ? ' for order ' . $intent->order->number : '' }}{{ $intent->invoice ? ' (invoice ' . $intent->invoice->number . ')' : '' }}.</p>
        <p class="text-muted small mb-0">Receipt {{ $intent->payment?->number }} · reference {{ $intent->reference }}</p>
    @elseif(in_array($intent->status, ['failed', 'abandoned']))
        <i class="ri-close-circle-line" style="font-size:48px;color:var(--accent)"></i>
        <h4 class="mt-3">The payment did not go through</h4>
        <p class="text-muted mb-3">No money was taken. You can try again with the same link.</p>
        @if($intent->authorization_url)<a href="{{ $intent->authorization_url }}" class="btn btn-accent">Try again</a>@endif
    @else
        <i class="ri-time-line" style="font-size:48px;color:var(--accent)"></i>
        <h4 class="mt-3">We are confirming your payment</h4>
        <p class="text-muted mb-3">This usually takes a minute. You can refresh this page; you will not be charged twice.</p>
        <a href="{{ request()->fullUrl() }}" class="btn btn-accent">Check again</a>
    @endif
</div>
@endsection
