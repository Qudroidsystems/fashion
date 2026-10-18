@php use App\Services\Reports\ReportFormat; $numeric = ['money', 'int', 'qty', 'percent', 'days']; $accent = $tenant->brand_color ?: '#0f2342'; @endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $report->title() }}</title>
<style>
    @page { margin: 26px 28px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f2937; }
    .head { border-bottom: 2px solid {{ $accent }}; padding-bottom: 10px; margin-bottom: 14px; }
    .head td { vertical-align: top; }
    .house { font-size: 13px; font-weight: bold; color: #0f2342; }
    .muted { color: #6b7280; }
    h1 { font-size: 17px; margin: 0 0 3px; color: {{ $accent }}; }
    .cards { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 14px; }
    .cards td { border: 1px solid #e5e7eb; border-top: 3px solid {{ $accent }}; padding: 7px 9px; width: 25%; vertical-align: top; }
    .cards .v { font-size: 13px; font-weight: bold; margin: 2px 0; }
    .cards .l { font-size: 8.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
    h2 { font-size: 11px; margin: 14px 0 5px; color: #0f2342; }
    table.t { width: 100%; border-collapse: collapse; }
    table.t th { background: #0f2342; color: #fff; text-align: left; padding: 4px 5px; font-size: 8.5px; font-weight: bold; }
    table.t td { padding: 3.5px 5px; border-bottom: 1px solid #eef0f3; }
    table.t tr:nth-child(even) td { background: #fafbfc; }
    .num, table.t th.num { text-align: right; white-space: nowrap; }
    tr.strong td { font-weight: bold; border-top: 1px solid #cbd5e1; }
    tr.indent td.first { padding-left: 16px; color: #4b5563; }
    tr.tone-danger td.first { border-left: 3px solid #e11d48; }
    tr.tone-warning td.first { border-left: 3px solid #f59e0b; }
    tfoot td { font-weight: bold; background: #f1f5f9; border-top: 1.5px solid #94a3b8; }
    .neg { color: #b91c1c; }
    .note { font-size: 8.5px; color: #6b7280; margin-top: 4px; }
    .notes { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 8.5px; color: #4b5563; }
    .foot { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8px; color: #9ca3af; }
</style>
</head>
<body>
    <div class="foot">{{ $tenant->name }} · {{ $report->title() }} · printed {{ now($f->tz)->format('j M Y, H:i') }} by {{ auth()->user()?->name }}</div>

    <table class="head" width="100%"><tr>
        <td>
            @if($logo)<img src="{{ $logo }}" style="height:38px;margin-bottom:4px"><br>@endif
            <span class="house">{{ $tenant->name }}</span><br>
            <span class="muted">{{ collect([$tenant->address ?? null, $tenant->phone, $tenant->email])->filter()->implode(' · ') }}</span>
        </td>
        <td style="text-align:right">
            <h1>{{ $report->title() }}</h1>
            <div>{{ $report->periodText($f) }}</div>
            @foreach($filterLabels as $k => $v)<div class="muted">{{ $k }}: {{ $v }}</div>@endforeach
        </td>
    </tr></table>

    @foreach(array_chunk($result->cards, 4) as $cards)
        <table class="cards"><tr>
            @foreach($cards as $c)
                <td><div class="l">{{ $c['label'] }}</div><div class="v">{{ ReportFormat::cell($c['value'], $c['format']) }}</div>@if($c['hint'])<div class="muted">{{ $c['hint'] }}</div>@endif</td>
            @endforeach
            @for($i = count($cards); $i < 4; $i++)<td style="border:0"></td>@endfor
        </tr></table>
    @endforeach

    @foreach($result->tables as $t)
        <h2>{{ $t['title'] }}</h2>
        @if(! $t['rows'])
            <div class="muted">{{ $t['empty'] ?? 'Nothing to show for these filters.' }}</div>
        @else
            <table class="t">
                <thead><tr>@foreach($t['columns'] as $c)<th class="{{ in_array($c['format'], $numeric) ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($t['rows'] as $row)
                    <tr class="{{ ! empty($row['_strong']) ? 'strong' : '' }} {{ ! empty($row['_indent']) ? 'indent' : '' }} {{ ! empty($row['_tone']) ? 'tone-' . $row['_tone'] : '' }}">
                        @foreach($t['columns'] as $i => $c)
                            @php $v = $row[$c['key']] ?? null; @endphp
                            <td class="{{ $i === 0 ? 'first' : '' }} {{ in_array($c['format'], $numeric) ? 'num' : '' }} {{ in_array($c['format'], $numeric) && is_numeric($v) && $v < 0 ? 'neg' : '' }}">{{ ReportFormat::cell($v, $c['format']) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
                @if($t['totals'])
                    <tfoot><tr>@foreach($t['columns'] as $c)<td class="{{ in_array($c['format'], $numeric) ? 'num' : '' }}">{{ isset($t['totals'][$c['key']]) ? ReportFormat::cell($t['totals'][$c['key']], $c['format']) : '' }}</td>@endforeach</tr></tfoot>
                @endif
            </table>
        @endif
        @if($t['note'])<div class="note">{{ $t['note'] }}</div>@endif
    @endforeach

    @if($result->notes)
        <div class="notes">@foreach($result->notes as $n)<div>• {{ $n }}</div>@endforeach</div>
    @endif
</body>
</html>
