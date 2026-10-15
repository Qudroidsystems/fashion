@php use App\Support\Money; @endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $sale->number }}</title>
<style>
    body { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; color: #000; margin: 0; background: #f3f4f6; }
    .r { width: 76mm; margin: 12px auto; background: #fff; padding: 10px 12px; }
    .c { text-align: center; } .b { font-weight: 700; } .row { display: flex; justify-content: space-between; gap: 8px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    .noprint { text-align: center; margin: 10px; } .noprint button { font-size: 14px; padding: 6px 14px; }
    @media print { body { background: #fff; } .r { margin: 0; width: 72mm; } .noprint { display: none; } @page { size: 80mm auto; margin: 2mm; } }
</style></head>
<body onload="setTimeout(() => window.print(), 300)">
<div class="noprint"><button onclick="window.print()">Print</button></div>
<div class="r">
    <div class="c b" style="font-size:15px">{{ $house->name }}</div>
    <div class="c">{{ $sale->branch->name }}@if($house->phone) · {{ $house->phone }}@endif</div>
    @if($house->address)<div class="c">{{ $house->address }}</div>@endif
    <hr>
    <div class="row"><span>{{ $sale->number }}</span><span>{{ $sale->created_at->timezone($tz)->format('d/m/Y H:i') }}</span></div>
    <div>Cashier: {{ $sale->cashier?->name }}</div>
    @if($sale->customer)<div>Customer: {{ $sale->customer->name }}</div>@endif
    @if($sale->status === 'voided')<div class="c b">*** VOIDED ***</div>@endif
    <hr>
    @foreach($sale->lines as $l)
        <div>{{ $l->description }}</div>
        <div class="row"><span>&nbsp; {{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }} × {{ Money::format($l->unit_price) }}{{ $l->discount > 0 ? ' −' . Money::format($l->discount) : '' }}</span><span>{{ Money::format($l->line_total) }}</span></div>
    @endforeach
    <hr>
    <div class="row b" style="font-size:14px"><span>TOTAL</span><span>{{ Money::format($sale->total) }}</span></div>
    @foreach($sale->tenders as $t)
        <div class="row"><span>{{ $t->method_label }}{{ $t->reference ? ' (' . $t->reference . ')' : '' }}</span><span>{{ Money::format($t->received ?? $t->amount) }}</span></div>
    @endforeach
    @if($sale->change_given > 0)<div class="row"><span>Change</span><span>{{ Money::format($sale->change_given) }}</span></div>@endif
    <hr>
    <div class="c">Thank you for your patronage!</div>
    <div class="c" style="font-size:10px">Keep this receipt for returns.</div>
</div>
</body></html>
