<?php

namespace App\Services\Messaging;

use App\Models\Customer;
use App\Models\MessageLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CustomerMessage;
use App\Services\Store\StoreSettings;
use App\Services\Store\WhatsApp;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a customer a message by e-mail, SMS and/or WhatsApp, and logs every attempt.
 *
 *  - E-mail: the platform's mail settings.
 *  - SMS: the house's own Termii or Twilio account (Messaging settings).
 *  - WhatsApp: the house's WhatsApp Cloud API connection (Online store settings). Messages a
 *    business starts need a template approved by Meta; set its name in Messaging settings.
 *
 * Service messages (e.g. "your fabric is still with us") go out unless the customer said no to
 * that channel for order & appointment updates.
 */
class Messenger
{
    /* ------------------------------ settings ------------------------------ */

    public static function settings(?Tenant $tenant = null): array
    {
        $t = $tenant ?? CurrentTenant::require();
        $s = (array) $t->setting('messaging', []);

        return [
            'sms_provider'      => $s['sms_provider'] ?? 'none',      // none, termii, twilio
            'sms_sender'        => $s['sms_sender'] ?? null,          // Termii sender ID
            'twilio_sid'        => $s['twilio_sid'] ?? null,
            'twilio_from'       => $s['twilio_from'] ?? null,
            'has_termii_key'    => ! empty($s['termii_key']),
            'has_twilio_token'  => ! empty($s['twilio_token']),
            'whatsapp_template' => $s['whatsapp_template'] ?? null,
            'whatsapp_lang'     => $s['whatsapp_lang'] ?? 'en',
        ];
    }

    /** $data: sms_provider, sms_sender, termii_key?, twilio_sid, twilio_token?, twilio_from, whatsapp_template, whatsapp_lang. Blank secrets keep the saved ones. */
    public static function saveSettings(array $data): void
    {
        $tenant = CurrentTenant::require();
        $settings = $tenant->settings ?? [];
        $m = (array) ($settings['messaging'] ?? []);
        foreach (['sms_provider', 'sms_sender', 'twilio_sid', 'twilio_from', 'whatsapp_template', 'whatsapp_lang'] as $k) {
            if (array_key_exists($k, $data)) {
                $m[$k] = $data[$k] !== null ? trim((string) $data[$k]) ?: null : null;
            }
        }
        foreach (['termii_key', 'twilio_token'] as $k) {
            if (! empty($data[$k])) {
                $m[$k] = Crypt::encryptString(trim($data[$k]));
            }
        }
        $settings['messaging'] = $m;
        $tenant->update(['settings' => $settings]);
        \App\Services\Audit\AuditLogger::log('messaging.settings_changed', $tenant, after: array_diff_key($m, array_flip(['termii_key', 'twilio_token'])));
    }

    protected static function secret(Tenant $tenant, string $key): ?string
    {
        $enc = data_get($tenant->setting('messaging', []), $key);
        try {
            return $enc ? Crypt::decryptString($enc) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Which channels this house can send on right now. */
    public function available(?Tenant $tenant = null): array
    {
        $t = $tenant ?? CurrentTenant::require();
        $s = self::settings($t);
        $sms = match ($s['sms_provider']) {
            'termii' => $s['has_termii_key'] && $s['sms_sender'],
            'twilio' => $s['twilio_sid'] && $s['has_twilio_token'] && $s['twilio_from'],
            default  => false,
        };

        return [
            'email'    => (bool) config('mail.default'),
            'sms'      => (bool) $sms,
            'whatsapp' => StoreSettings::secret($t, 'whatsapp.cloud_token') && StoreSettings::get($t, 'whatsapp.cloud_phone_id'),
        ];
    }

    /* ------------------------------- sending ------------------------------- */

    /**
     * @param array $channels e.g. ['email', 'sms', 'whatsapp']
     * @param array $opts purpose, related (Model), by (User), whatsapp_params (string[] for the template)
     * @return Collection<MessageLog>
     */
    public function send(Customer $customer, array $channels, string $subject, string $text, array $opts = []): Collection
    {
        $tenant = CurrentTenant::require();
        $customer->loadMissing('consents');
        $ready = $this->available($tenant);
        $logs = collect();

        foreach (array_unique($channels) as $channel) {
            $to = match ($channel) {
                'email'    => $customer->email,
                'sms'      => WhatsApp::normalize($customer->phone, $tenant),
                'whatsapp' => WhatsApp::normalize($customer->whatsapp ?: $customer->phone, $tenant),
                default    => null,
            };
            [$status, $error] = match (true) {
                ! isset($ready[$channel])  => ['skipped', 'Unknown channel.'],
                ! $to                      => ['skipped', $channel === 'email' ? 'No e-mail address.' : 'No phone number.'],
                $this->refused($customer, $channel) => ['skipped', 'The customer asked not to get messages this way.'],
                ! $ready[$channel]         => ['skipped', ucfirst(MessageLog::CHANNELS[$channel]) . ' is not set up for this house.'],
                default                    => $this->deliver($tenant, $channel, $to, $customer, $subject, $text, $opts),
            };
            $logs->push(MessageLog::create([
                'customer_id' => $customer->id, 'channel' => $channel, 'to' => $to, 'subject' => $subject, 'body' => $text,
                'status' => $status, 'error' => $error ? mb_substr($error, 0, 255) : null, 'purpose' => $opts['purpose'] ?? null,
                'related_type' => isset($opts['related']) ? $opts['related']->getMorphClass() : null,
                'related_id' => isset($opts['related']) ? $opts['related']->getKey() : null,
                'sent_by' => isset($opts['by']) && $opts['by'] instanceof User ? $opts['by']->id : null,
            ]));
        }

        return $logs;
    }

    protected function refused(Customer $c, string $channel): bool
    {
        $consent = $c->consents->first(fn ($x) => $x->channel === $channel && $x->purpose === 'transactional');

        return $consent !== null && ! $consent->granted;
    }

    /** @return array{0: string, 1: ?string} [status, error] */
    protected function deliver(Tenant $tenant, string $channel, string $to, Customer $customer, string $subject, string $text, array $opts): array
    {
        try {
            return match ($channel) {
                'email'    => $this->email($tenant, $to, $customer, $subject, $text),
                'sms'      => $this->sms($tenant, $to, $text),
                'whatsapp' => $this->whatsapp($tenant, $to, $text, $opts['whatsapp_params'] ?? null),
            };
        } catch (\Throwable $e) {
            Log::warning('Customer message failed', ['channel' => $channel, 'error' => $e->getMessage()]);

            return ['failed', $e->getMessage()];
        }
    }

    protected function email(Tenant $tenant, string $to, Customer $customer, string $subject, string $text): array
    {
        Notification::route('mail', $to)->notify(new CustomerMessage($subject, $text, $tenant->name, $customer->first_name, $tenant->id));

        return ['sent', null];
    }

    protected function sms(Tenant $tenant, string $to, string $text): array
    {
        $s = self::settings($tenant);
        $text = mb_substr($text, 0, 600);
        if ($s['sms_provider'] === 'termii') {
            $res = Http::acceptJson()->timeout(20)->post(config('services.termii.url', 'https://api.ng.termii.com') . '/api/sms/send', [
                'api_key' => self::secret($tenant, 'termii_key'), 'to' => $to, 'from' => $s['sms_sender'],
                'sms' => $text, 'type' => 'plain', 'channel' => 'generic',
            ]);

            return $res->successful() && ($res->json('code') === 'ok' || $res->json('message_id'))
                ? ['sent', null] : ['failed', 'Termii: ' . ($res->json('message') ?? 'error ' . $res->status())];
        }
        $res = Http::asForm()->withBasicAuth($s['twilio_sid'], (string) self::secret($tenant, 'twilio_token'))->timeout(20)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$s['twilio_sid']}/Messages.json", [
                'To' => '+' . $to, 'From' => $s['twilio_from'], 'Body' => $text,
            ]);

        return $res->successful() ? ['sent', null] : ['failed', 'Twilio: ' . ($res->json('message') ?? 'error ' . $res->status())];
    }

    protected function whatsapp(Tenant $tenant, string $to, string $text, ?array $params): array
    {
        $s = self::settings($tenant);
        $endpoint = 'https://graph.facebook.com/' . config('store.whatsapp_api_version', 'v21.0') . '/' . StoreSettings::get($tenant, 'whatsapp.cloud_phone_id') . '/messages';
        $body = $s['whatsapp_template'] && $params
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $s['whatsapp_template'], 'language' => ['code' => $s['whatsapp_lang'] ?: 'en'],
                // Template values may not contain line breaks.
                'components' => [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => mb_substr(preg_replace('/\s+/', ' ', (string) $p), 0, 900)], $params)]],
            ]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]];
        $res = Http::withToken((string) StoreSettings::secret($tenant, 'whatsapp.cloud_token'))->acceptJson()->timeout(20)->post($endpoint, $body);

        return $res->successful() ? ['sent', null] : ['failed', 'WhatsApp: ' . ($res->json('error.message') ?? 'error ' . $res->status())];
    }

    /** A wa.me link with the message ready, for staff to send from their own WhatsApp. */
    public static function whatsAppLink(Customer $customer, string $text): ?string
    {
        return WhatsApp::link(WhatsApp::normalize($customer->whatsapp ?: $customer->phone, CurrentTenant::get()), $text);
    }
}
