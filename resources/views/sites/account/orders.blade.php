@extends('sites.account._layout')

@section('account')
    <div class="account-orders">
        @if($orders->isEmpty())
            <div class="store-empty"><h5>No orders yet</h5><a href="{{ $base }}/shop" class="tf-btn btn-fill mt-3"><span class="text">Start shopping</span></a></div>
        @else
        <div class="wrap-account-order">
            <table>
                <thead><tr><th class="fw-6">Order</th><th class="fw-6">Date</th><th class="fw-6">Status</th><th class="fw-6">Total</th><th class="fw-6"></th></tr></thead>
                <tbody>
                @foreach($orders as $o)
                    <tr class="tf-order-item">
                        <td>{{ $o->number }}</td>
                        <td>{{ $o->created_at->setTimezone($house->timezone ?: config('app.timezone'))->format('j M Y') }}</td>
                        <td>{{ $o->customer_status_label }}<br><small class="text-secondary">{{ $o->payment_status_label }}</small></td>
                        <td>{{ \App\Support\Money::format($o->total, $o->currency) }} for {{ $o->itemCount() }} {{ \Illuminate\Support\Str::plural('item', $o->itemCount()) }}</td>
                        <td><a href="{{ $base }}/order/{{ $o->token }}" class="tf-btn btn-fill radius-4"><span class="text">View</span></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $orders->links() }}</div>
        @endif
    </div>
@endsection
