@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php $house = \App\Support\Tenancy\CurrentTenant::get()->name; @endphp
    <x-cb.hero title="New campaign" icon="ri-megaphone-line" subtitle="Only customers who agreed to promotions on a channel get it there. Every message ends with how to stop them."
               :back="route('campaigns.index')" back-label="Campaigns" />
    @include('inventory.partials.flash')
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <form method="post" action="{{ route('campaigns.store') }}" id="campForm">@csrf
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Message" icon="ri-message-2-line">
                    <label class="form-label">Name <span class="text-muted">(only you see this)</span></label>
                    <input name="name" class="form-control mb-3" required maxlength="120" value="{{ old('name') }}" placeholder="e.g. Christmas 2026 offer">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">What it says</label>
                            <textarea name="body" id="campBody" rows="6" maxlength="900" class="form-control" required placeholder="Hello {first_name}, our Christmas collection is in! Come in before 20 December for 10% off any outfit. — {house}">{{ old('body') }}</textarea>
                            <div class="d-flex justify-content-between mt-1 small">
                                <span><button type="button" class="btn btn-link btn-sm p-0" onclick="ins('{first_name}')"><code>{first_name}</code></button> · <button type="button" class="btn btn-link btn-sm p-0" onclick="ins('{house}')"><code>{house}</code></button></span>
                                <span class="text-muted" id="campCount"></span>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="small text-muted mb-1">How it reads</div>
                            <div id="campPreview" class="cm-bubble"></div>
                        </div>
                    </div>
                </x-cb.card>
                <x-cb.card title="Who gets it" icon="ri-group-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <select name="audience_type" class="form-select" id="campAud">
                                @foreach(\App\Models\Campaign::AUDIENCES as $k => $l)<option value="{{ $k }}" @selected(old('audience_type', 'all') === $k)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-6 d-none" data-aud="tags">
                            <select name="tag_ids[]" class="form-select" multiple size="3">@foreach($tags as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
                        </div>
                        <div class="col-md-6 d-none" data-aud="recent lapsed">
                            <div class="input-group"><input type="number" name="days" class="form-control" min="1" max="1000" value="{{ old('days', 90) }}"><span class="input-group-text">days</span></div>
                        </div>
                    </div>
                    <div class="cb-banner info mt-3 mb-0"><i class="ri-team-line"></i><div id="campReach">Counting…</div></div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Send by" icon="ri-route-line">
                    @foreach(\App\Services\Messaging\CustomerUpdates::CHANNEL_LABELS as $k => $l)
                        <label class="d-flex gap-2 mb-2 {{ ($available[$k] ?? false) ? '' : 'text-muted' }}">
                            <input type="checkbox" name="channels[]" value="{{ $k }}" @checked(($available[$k] ?? false) && in_array($k, old('channels', array_keys(array_filter($available)))))) @disabled(! ($available[$k] ?? false))>
                            {{ $l }} @unless($available[$k] ?? false)<span class="small">— <a href="{{ route('settings.messaging') }}">not set up</a></span>@endunless
                        </label>
                    @endforeach
                    <div class="form-text">Each customer gets one message, on the first ticked channel they agreed to (in this order).</div>
                </x-cb.card>
                <x-cb.card title="When" icon="ri-time-line" class="mt-3">
                    <label class="d-flex gap-2 mb-2"><input type="radio" name="when" value="now" checked onchange="campWhen()"> Now</label>
                    <label class="d-flex gap-2 mb-2"><input type="radio" name="when" value="later" onchange="campWhen()"> Later</label>
                    <input type="datetime-local" name="send_at" class="form-control mb-2 d-none" id="campAt" min="{{ now($tz)->format('Y-m-d\TH:i') }}">
                    <label class="d-flex gap-2 mb-3"><input type="radio" name="when" value="draft" onchange="campWhen()"> Save as a draft</label>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2" onclick="return document.querySelector('[name=when]:checked').value === 'draft' || confirm('Send this to ' + (window.campN || 0) + ' customers?')"><i class="ri-send-plane-line"></i>Go</button>
                </x-cb.card>
            </div>
        </div>
    </form>
</div>
</div>
</div>
<style>.cm-bubble{white-space:pre-line;background:#dcf8c6;color:#111;border-radius:12px 12px 12px 3px;padding:10px 12px;font-size:.86rem;line-height:1.45;min-height:60px}[data-bs-theme=dark] .cm-bubble{background:#005c4b;color:#e9edef}</style>
<script>
const campHouse = @json($house), campFooter = @json(\App\Services\Campaigns\CampaignService::FOOTER);
const f = document.getElementById('campForm'), body = document.getElementById('campBody');
function ins(t) { const s = body.selectionStart; body.value = body.value.slice(0, s) + t + body.value.slice(body.selectionEnd); body.focus(); preview(); }
function preview() {
    const text = body.value.split('{first_name}').join('Ada').split('{house}').join(campHouse).trim() + campFooter;
    document.getElementById('campPreview').textContent = text;
    document.getElementById('campCount').textContent = text.length + ' characters · about ' + Math.max(1, Math.ceil(text.length / 160)) + ' SMS each';
}
function campWhen() { document.getElementById('campAt').classList.toggle('d-none', f.when.value !== 'later'); }
let t;
function reach() {
    const aud = document.getElementById('campAud').value;
    document.querySelectorAll('[data-aud]').forEach(e => e.classList.toggle('d-none', !e.dataset.aud.split(' ').includes(aud)));
    clearTimeout(t);
    t = setTimeout(async () => {
        const qs = new URLSearchParams(new FormData(f)); qs.delete('_token'); qs.delete('body'); qs.delete('name');
        const r = await fetch(@json(route('campaigns.audience')) + '?' + qs, { headers: { Accept: 'application/json' } });
        const d = await r.json(); window.campN = d.reachable;
        document.getElementById('campReach').innerHTML = '<strong>' + d.reachable + '</strong> of ' + d.in_group + ' customers in this group can be reached'
            + (d.reachable < d.in_group ? ' — the rest haven’t agreed to promotions on the ticked channels.' : '.');
    }, 250);
}
body.addEventListener('input', preview); f.addEventListener('change', reach); preview(); reach();
</script>
@endsection
