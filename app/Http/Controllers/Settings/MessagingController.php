<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MessageLog;
use App\Services\Messaging\Messenger;
use App\Services\Store\WhatsApp;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** House messaging: the SMS account, the WhatsApp template, a test send, and the log of messages sent. */
class MessagingController extends Controller
{
    public function __construct(protected Messenger $messenger)
    {
        $this->middleware('permission:Manage tenant settings');
    }

    public function edit(): View
    {
        return view('settings.messaging', [
            'settings' => Messenger::settings(),
            'available' => $this->messenger->available(),
            'log' => MessageLog::with('customer:id,public_id,first_name,last_name', 'sender:id,name')->latest('id')->limit(50)->get(),
            'tz' => CurrentTenant::get()->timezone,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sms_provider' => 'required|in:none,termii,twilio',
            'sms_sender' => 'nullable|string|max:11|required_if:sms_provider,termii',
            'termii_key' => 'nullable|string|max:200',
            'twilio_sid' => 'nullable|string|max:64|required_if:sms_provider,twilio',
            'twilio_token' => 'nullable|string|max:200',
            'twilio_from' => 'nullable|string|max:30|required_if:sms_provider,twilio',
            'whatsapp_template' => 'nullable|string|max:120|regex:/^[a-z0-9_]+$/',
            'whatsapp_update_template' => 'nullable|string|max:120|regex:/^[a-z0-9_]+$/',
            'whatsapp_lang' => 'nullable|string|max:10',
        ], ['whatsapp_template.regex' => 'Template names use small letters, numbers and _ only, as in Meta.', 'whatsapp_update_template.regex' => 'Template names use small letters, numbers and _ only, as in Meta.']);
        Messenger::saveSettings($data);

        return back()->with('success', 'Messaging settings saved.');
    }

    /** Send a test to the person's own phone / e-mail. */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['channel' => 'required|in:email,sms,whatsapp', 'to' => 'required|string|max:190']);
        $tenant = CurrentTenant::require();
        $fake = new Customer(['first_name' => $request->user()->first_name ?? 'there']);
        $fake->setRelation('consents', collect());
        $fake->forceFill($data['channel'] === 'email' ? ['email' => $data['to']] : ['phone' => $data['to'], 'whatsapp' => $data['to']]);
        $log = $this->messenger->send($fake, [$data['channel']], "Test from {$tenant->name}",
            "Hello,\n\nThis is a test message from {$tenant->name}. If you can read this, " . MessageLog::CHANNELS[$data['channel']] . ' messages are working.',
            ['purpose' => 'test', 'by' => $request->user(), 'whatsapp_params' => ['there', 'a test message', $tenant->name]])->first();

        return back()->with($log->status === 'sent' ? 'success' : 'warning', $log->status === 'sent'
            ? 'Test sent to ' . ($data['channel'] === 'email' ? $data['to'] : '+' . WhatsApp::normalize($data['to'], $tenant)) . '.'
            : 'Not sent: ' . $log->error);
    }
}
