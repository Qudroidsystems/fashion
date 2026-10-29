@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php use App\Models\MessageLog; $s = $settings; @endphp

    <x-cb.hero title="Messaging" icon="ri-message-3-line" subtitle="How the platform reaches your customers: e-mail, SMS and WhatsApp. Used for reminders such as collecting materials." />
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        @foreach(MessageLog::CHANNELS as $k => $label)
            <div class="col-md-4">
                <x-cb.stat :label="$label" :value="($available[$k] ?? false) ? 'Ready' : 'Not set up'" :icon="['email' => 'ri-mail-line', 'sms' => 'ri-chat-1-line', 'whatsapp' => 'ri-whatsapp-line'][$k]"
                    :accent="($available[$k] ?? false) ? 'teal' : 'amber'"
                    :hint="['email' => 'Uses the platform’s e-mail', 'sms' => 'Your Termii or Twilio account', 'whatsapp' => 'Your WhatsApp Cloud API connection'][$k]" />
            </div>
        @endforeach
    </div>

    <form method="post" action="{{ route('settings.messaging.update') }}">
        @csrf @method('put')
        <div class="row g-3">
            <div class="col-lg-6">
                <x-cb.card title="SMS" icon="ri-chat-1-line">
                    <label class="form-label">Provider</label>
                    <select name="sms_provider" class="form-select mb-3" onchange="document.querySelectorAll('[data-sms]').forEach(e => e.classList.toggle('d-none', e.dataset.sms !== this.value))">
                        <option value="none" @selected($s['sms_provider'] === 'none')>No SMS</option>
                        <option value="termii" @selected($s['sms_provider'] === 'termii')>Termii (Nigeria)</option>
                        <option value="twilio" @selected($s['sms_provider'] === 'twilio')>Twilio</option>
                    </select>
                    <div data-sms="termii" class="{{ $s['sms_provider'] === 'termii' ? '' : 'd-none' }}">
                        <label class="form-label">Sender ID</label>
                        <input name="sms_sender" class="form-control mb-1" maxlength="11" value="{{ old('sms_sender', $s['sms_sender']) }}" placeholder="e.g. AdaStitches">
                        <div class="form-text mb-3">Up to 11 letters, approved on your Termii account.</div>
                        <label class="form-label">API key</label>
                        <input name="termii_key" type="password" autocomplete="new-password" class="form-control mb-1" placeholder="{{ $s['has_termii_key'] ? 'Saved — leave empty to keep' : 'From Termii › API settings' }}">
                    </div>
                    <div data-sms="twilio" class="{{ $s['sms_provider'] === 'twilio' ? '' : 'd-none' }}">
                        <label class="form-label">Account SID</label>
                        <input name="twilio_sid" class="form-control mb-3" value="{{ old('twilio_sid', $s['twilio_sid']) }}">
                        <label class="form-label">Auth token</label>
                        <input name="twilio_token" type="password" autocomplete="new-password" class="form-control mb-3" placeholder="{{ $s['has_twilio_token'] ? 'Saved — leave empty to keep' : '' }}">
                        <label class="form-label">From number</label>
                        <input name="twilio_from" class="form-control" value="{{ old('twilio_from', $s['twilio_from']) }}" placeholder="+1…">
                    </div>
                    <div class="form-text mt-2">Messages are sent from your own account and charged by your provider. Keys are stored encrypted.</div>
                </x-cb.card>
            </div>
            <div class="col-lg-6">
                <x-cb.card title="WhatsApp" icon="ri-whatsapp-line">
                    @if($available['whatsapp'])
                        <p class="small mb-3"><i class="ri-checkbox-circle-line text-success me-1"></i>Connected through your WhatsApp Cloud API settings (Online store › WhatsApp).</p>
                    @else
                        <p class="small mb-3 text-muted">Connect the WhatsApp Cloud API in Online store › WhatsApp to send automatically. Until then, staff can still send each reminder from their own WhatsApp with one tap.</p>
                    @endif
                    <label class="form-label">Reminder template name</label>
                    <input name="whatsapp_template" class="form-control mb-1" maxlength="120" value="{{ old('whatsapp_template', $s['whatsapp_template']) }}" placeholder="e.g. material_reminder">
                    <div class="form-text mb-3">WhatsApp only lets businesses start a chat with a template approved by Meta. Create one with three values, for example:<br>
                        <em>Hello @{{1}}, we are still keeping your @{{2}} at @{{3}}. Please come and collect it, or reply to arrange delivery.</em><br>
                        @{{1}} = first name, @{{2}} = the items, @{{3}} = your house name. Without a template, messages only reach customers who wrote to you in the last 24 hours.</div>
                    <label class="form-label">Template language</label>
                    <input name="whatsapp_lang" class="form-control" maxlength="10" value="{{ old('whatsapp_lang', $s['whatsapp_lang']) }}" placeholder="en">
                </x-cb.card>
            </div>
            <div class="col-12"><button class="btn btn-primary">Save</button></div>
        </div>
    </form>

    <div class="row g-3 mt-1">
        <div class="col-lg-5">
            <x-cb.card title="Send a test" icon="ri-send-plane-line">
                <form method="post" action="{{ route('settings.messaging.test') }}" class="row g-2">@csrf
                    <div class="col-4"><select name="channel" class="form-select">@foreach(MessageLog::CHANNELS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-8"><input name="to" class="form-control" required placeholder="Your e-mail or phone"></div>
                    <div class="col-12"><button class="btn btn-light">Send test</button></div>
                </form>
            </x-cb.card>
        </div>
        <div class="col-lg-7">
            <x-cb.card title="Recent messages" icon="ri-history-line" :count="$log->count()" :flush="true">
                @if($log->isEmpty())<div class="p-3 text-muted small">Nothing sent yet.</div>@else
                <div class="table-responsive"><table class="cb-table stack mb-0">
                    <thead><tr><th>When</th><th>To</th><th>How</th><th>Status</th></tr></thead>
                    <tbody>@foreach($log as $l)
                        <tr>
                            <td data-label="When" class="text-nowrap">{{ $l->created_at->timezone($tz)->format('j M, g:i A') }}</td>
                            <td data-label="To">@if($l->customer)<a href="{{ route('customers.show', $l->customer) }}">{{ $l->customer->name }}</a>@else{{ $l->to }}@endif<div class="small text-muted">{{ $l->subject }}</div></td>
                            <td data-label="How">{{ MessageLog::CHANNELS[$l->channel] ?? $l->channel }}<div class="small text-muted">{{ $l->sender?->name ?? 'Automatic' }}</div></td>
                            <td data-label="Status"><span class="status-pill {{ $l->status === 'sent' ? 'st-success' : ($l->status === 'failed' ? 'st-danger' : 'st-muted') }}">{{ ucfirst($l->status) }}</span>@if($l->error)<div class="small text-muted">{{ $l->error }}</div>@endif</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>@endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
