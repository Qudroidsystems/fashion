@php use App\Models\Delivery; use App\Support\Money; $d = $delivery; @endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $d->number }}</title>
<style>
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color:#111; background:#f3f4f6; margin:0; }
    .page { max-width: 720px; margin: 24px auto; background:#fff; padding: 32px; border-radius: 8px; }
    h1 { font-size: 20px; margin: 0 0 4px; } .muted { color:#6b7280; } table { width:100%; border-collapse: collapse; margin: 16px 0; }
    td, th { text-align:left; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 14px; } .row { display:flex; justify-content:space-between; gap: 16px; }
    .sign { margin-top: 48px; display:flex; gap: 32px; } .sign div { flex:1; border-top: 1px solid #111; padding-top: 6px; font-size: 13px; }
    .noprint { text-align:center; margin: 12px; } @media print { body { background:#fff; } .page { margin:0; border-radius:0; } .noprint { display:none; } }
</style></head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button> <a href="{{ route('orders.show', $d->order) }}">Back to order</a></div>
<div class="page">
    <div class="row"><div><h1>{{ $house->name }}</h1><div class="muted">{{ $house->address }} {{ $house->phone }}</div></div>
        <div style="text-align:right"><strong>{{ $d->method === 'delivery' ? 'Delivery note' : 'Collection note' }}</strong><div>{{ $d->number }}</div><div class="muted">{{ $d->handed_over_at->timezone($tz)->format('d M Y, H:i') }}</div></div></div>
    <p style="margin-top:24px">Order <strong>{{ $d->order->number }}</strong> for <strong>{{ $d->order->customer->name }}</strong>{{ $d->order->title ? ' — ' . $d->order->title : '' }}</p>
    <table><thead><tr><th>Item</th><th>Qty</th></tr></thead><tbody>
        @foreach($lines as $l)<tr><td>{{ $l->description }}{{ $l->wearer ? ' (for ' . $l->wearer . ')' : '' }}</td><td>{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }}</td></tr>@endforeach
    </tbody></table>
    <p>Received by <strong>{{ $d->recipient_name }}</strong>{{ $d->relationship ? ' (' . $d->relationship . ')' : '' }}{{ $d->recipient_phone ? ' · ' . $d->recipient_phone : '' }}.
        Confirmation: {{ Delivery::CONFIRMATIONS[$d->confirmation] }}{{ $d->confirmation_ref ? ' — ' . $d->confirmation_ref : '' }}.</p>
    @if($d->address)<p>Delivered to: {{ $d->address }}</p>@endif
    @if($d->balance_at_handover > 0)<p><strong>Balance outstanding at handover: {{ Money::format($d->balance_at_handover) }}.</strong> {{ $d->outstanding_conditions }}</p>@endif
    @if($d->evidence_url)<p><img src="{{ $d->evidence_url }}" alt="" style="max-height:160px;border-radius:6px"></p>@endif
    <div class="sign"><div>Received by</div><div>Handed over by {{ $d->user?->name }}</div></div>
</div>
</body></html>
