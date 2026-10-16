@extends('sites.layout')
@section('title', 'Ready to wear')

@section('body')
    <section>
        <div class="wrap">
            <h1 style="font-size:clamp(2.4rem,5vw,4rem)">Ready to wear</h1>
            <p class="muted" style="margin:1rem 0 40px;max-width:60ch">Pieces you can take home today, in the sizes shown. Ask us to hold one for you.</p>
            @if($products->isEmpty())
                <p class="notice">Nothing on the rail right now. <a href="{{ $base }}/collection">See the made-to-measure collection</a>.</p>
            @else
            <div class="grid">
                @foreach($products as $p)
                    <div class="card">
                        <div class="pic">@include('sites.partials-pic', ['src' => $p->image_url, 'alt' => $p->name])</div>
                        <h3>{{ $p->name }}</h3>
                        <div class="price">@if($site['show_prices'] ?? true){{ \App\Support\Money::format($p->variants->min('price')) }} · @endif{{ $p->variants->pluck('size')->filter()->unique()->implode(', ') ?: $p->variants->pluck('label')->filter()->unique()->implode(', ') }}</div>
                        <p style="margin-top:.7rem"><a href="{{ $base }}/contact?product={{ $p->public_id }}" style="color:var(--accent);font-weight:600;text-decoration:none">Ask to hold this</a></p>
                    </div>
                @endforeach
            </div>
            @endif
        </div>
    </section>
@endsection
