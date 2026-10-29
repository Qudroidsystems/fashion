@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php
    use App\Services\Messaging\CustomerUpdates;
    $s = $settings;
    $chosen = array_values($s['channels']);
    $jsSample = [
        '{first_name}' => 'Ada', '{house}' => \App\Support\Tenancy\CurrentTenant::get()->name, '{order}' => 'ORD-2026-00042', '{items}' => 'agbada and cap', '{is_are}' => 'are',
        '{total}' => \App\Support\Money::format(185000), '{paid}' => \App\Support\Money::format(100000), '{balance}' => \App\Support\Money::format(85000),
        '{balance_line}' => 'Balance on ORD-2026-00042: ' . \App\Support\Money::format(85000) . '.', '{amount}' => \App\Support\Money::format(50000),
        '{method}' => 'bank transfer', '{receipt}' => 'RCP-2026-00108', '{pickup}' => \App\Support\Tenancy\CurrentTenant::get()->name,
        '{phone}' => \App\Support\Tenancy\CurrentTenant::get()->phone ?: '0803 000 0000', '{fitting_time}' => 'Saturday 14 November, 11:00am', '{appointment_time}' => 'Monday 16 November, 2:00pm', '{appointment_type}' => 'consultation',
        '{pay_line}' => '', '{due_line}' => ' Expected ready by 20 Nov.',
    ];
@endphp

    <x-cb.hero title="Customer messages" icon="ri-chat-settings-line"
               subtitle="What customers hear from you automatically — order confirmed, payment received, ready to collect, fittings and balances — and the words you use."
               :back="route('settings.messaging')" back-label="Messaging" />
    @include('inventory.partials.flash')

    <form method="post" action="{{ route('settings.customer-messages.update') }}">
        @csrf @method('put')

        <div class="row g-3">
            <div class="col-xl-4">
                <x-cb.card title="How to reach customers" icon="ri-route-line">
                    <p class="small text-muted">In order of preference. Channels that aren’t set up, or that a customer said no to, are skipped.</p>
                    @for($i = 0; $i < 3; $i++)
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-light text-body rounded-pill" style="width:26px">{{ $i + 1 }}</span>
                            <select name="channels[]" class="form-select form-select-sm">
                                <option value="">—</option>
                                @foreach(CustomerUpdates::CHANNEL_LABELS as $k => $label)
                                    <option value="{{ $k }}" @selected(($chosen[$i] ?? null) === $k)>{{ $label }}{{ ($available[$k] ?? false) ? '' : ' (not set up)' }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endfor
                    <label class="d-flex gap-2 mt-3 small">
                        <input type="hidden" name="first_only" value="0">
                        <input type="checkbox" name="first_only" value="1" @checked($s['first_only'])>
                        <span><strong>One message per update</strong> — use the first channel that works. Untick to send on every channel.</span>
                    </label>
                    @if(! ($available['whatsapp'] ?? false) && ! ($available['sms'] ?? false))
                        <div class="cb-banner warning mt-3 mb-0"><i class="ri-information-line"></i><div>Only e-mail is set up, so customers without an e-mail address won’t get updates. <a href="{{ route('settings.messaging') }}">Set up SMS or WhatsApp</a>.</div></div>
                    @endif
                </x-cb.card>

                <x-cb.card title="Balance reminders" icon="ri-wallet-3-line" class="mt-3">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label small">First reminder, days after ready / collected</label>
                            <input type="number" name="balance_after_days" min="0" max="60" class="form-control" value="{{ $s['balance_after_days'] }}"></div>
                        <div class="col-6"><label class="form-label small">Then every (days)</label>
                            <input type="number" name="balance_repeat_days" min="1" max="60" class="form-control" value="{{ $s['balance_repeat_days'] }}"></div>
                        <div class="col-6"><label class="form-label small">At most</label>
                            <input type="number" name="balance_max" min="1" max="10" class="form-control" value="{{ $s['balance_max'] }}"></div>
                    </div>
                    <div class="form-text">Only between 9am and 7pm. Stops as soon as the balance is paid.</div>
                </x-cb.card>

                <x-cb.card title="Words you can use" icon="ri-braces-line" class="mt-3">
                    <p class="small text-muted mb-2">Click one to put it where you’re typing.</p>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach(CustomerUpdates::PLACEHOLDERS as $ph => $desc)
                            <button type="button" class="btn btn-light btn-sm py-0 px-2 cm-ph" data-ph="{{ $ph }}" title="{{ $desc }}"><code>{{ $ph }}</code></button>
                        @endforeach
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-8">
                @foreach(CustomerUpdates::EVENTS as $key => [$label, $onByDefault, $default, $when])
                    @php $e = $s['events'][$key]; @endphp
                    <x-cb.card class="{{ $loop->first ? '' : 'mt-3' }} cm-event" :title="$label" icon="ri-message-2-line">
                        <x-slot:tools>
                            <div class="form-check form-switch mb-0">
                                <input type="hidden" name="events[{{ $key }}][on]" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="events[{{ $key }}][on]" value="1" @checked($e['on']) onchange="this.closest('.cm-event').classList.toggle('cm-off', !this.checked)">
                            </div>
                        </x-slot:tools>
                        <div class="{{ $e['on'] ? '' : 'cm-off' }}">
                            <p class="small text-muted mb-2">{{ $when }}</p>
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <textarea name="events[{{ $key }}][text]" rows="5" maxlength="1000" class="form-control cm-text" data-default="{{ $default }}">{{ old("events.$key.text", $e['text']) }}</textarea>
                                    <div class="d-flex justify-content-between mt-1">
                                        <button type="button" class="btn btn-link btn-sm p-0 cm-reset">Use the standard wording</button>
                                        <span class="small text-muted cm-count"></span>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="small text-muted mb-1">How it reads</div>
                                    <div class="cm-bubble" data-sample="{{ $samples[$key] }}">{{ $samples[$key] }}</div>
                                </div>
                            </div>
                        </div>
                    </x-cb.card>
                @endforeach
                <div class="mt-3"><button class="action-btn btn-primary-cb"><i class="ri-save-line"></i>Save</button></div>
            </div>
        </div>
    </form>

<style>
    .cm-bubble { white-space: pre-line; background: #dcf8c6; color: #111; border-radius: 12px 12px 12px 3px; padding: 10px 12px; font-size: .86rem; line-height: 1.45; box-shadow: 0 1px 1px rgba(0,0,0,.08); }
    [data-bs-theme=dark] .cm-bubble { background: #005c4b; color: #e9edef; }
    .cm-off { opacity: .45; }
    .cm-event .cm-off { pointer-events: auto; }
</style>
<script>
(function () {
    // Same sample values as the server uses, so the preview updates as you type.
    const sample = @json($jsSample);
    let last = null;
    function render(t) { let out = t; for (const [k, v] of Object.entries(sample)) out = out.split(k).join(v); return out.replace(/\{[a-z_]+\}/g, '').replace(/\n{3,}/g, '\n\n').trim(); }
    document.querySelectorAll('.cm-event').forEach(card => {
        const ta = card.querySelector('.cm-text'), bubble = card.querySelector('.cm-bubble'), count = card.querySelector('.cm-count');
        const update = () => {
            const text = render(ta.value);
            bubble.textContent = text;
            const sms = Math.max(1, Math.ceil(text.length / 160));
            count.textContent = text.length + ' characters · about ' + sms + ' SMS' + (sms > 1 ? 'es' : '');
        };
        ta.addEventListener('input', update);
        ta.addEventListener('focus', () => last = ta);
        card.querySelector('.cm-reset').onclick = () => { ta.value = ta.dataset.default; update(); };
        update();
    });
    document.querySelectorAll('.cm-ph').forEach(b => b.onclick = () => {
        if (!last) return;
        const s = last.selectionStart, e = last.selectionEnd;
        last.value = last.value.slice(0, s) + b.dataset.ph + last.value.slice(e);
        last.focus(); last.selectionStart = last.selectionEnd = s + b.dataset.ph.length;
        last.dispatchEvent(new Event('input'));
    });
})();
</script>

</div>
</div>
</div>
@endsection
