<?php

namespace App\Services\Messaging;

use App\Models\Customer;
use App\Models\Fitting;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\OutgoingMessage;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Store\StoreSettings;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Keeps customers in the loop by WhatsApp, SMS or e-mail: order confirmed, payment received,
 * garments ready, fitting reminders, a gentle nudge about a balance, a thank-you — plus messages
 * staff write themselves.
 *
 * Every message goes into the outbox (outgoing_messages) and is sent straight after the page
 * that created it has responded, so saving an order never waits on WhatsApp or the SMS
 * provider. The scheduler retries anything that failed. Each house chooses which updates go,
 * edits the wording, and picks the channels in order of preference — by default the first one
 * that works (WhatsApp, then SMS, then e-mail), so the customer gets one message, not three.
 */
class CustomerUpdates
{
    /** event => [label, on by default, default text, when it goes] */
    public const EVENTS = [
        'order_confirmed' => ['Order confirmed', true,
            "Hello {first_name}, thank you for your order {order} with {house}. Total {total}, paid so far {paid}.{due_line}\n\nWe'll keep you posted.",
            'When an order is confirmed.'],
        'payment_received' => ['Payment received', true,
            "Hello {first_name}, we've received {amount} by {method}. Receipt {receipt}.\n\n{balance_line}\n\nThank you — {house}",
            'When a payment is recorded for an order (not for walk-in till sales).'],
        'order_ready' => ['Ready for collection', true,
            "Hello {first_name}, good news! Your {items} ({order}) {is_are} ready for collection at {pickup}.\n\n{balance_line}",
            'When every garment in the order is ready.'],
        'fitting_reminder' => ['Fitting reminder', true,
            "Hello {first_name}, a reminder of your fitting on {fitting_time} at {pickup}. If you can't make it, call us on {phone} so we can move it.",
            'With the fitting reminders (the e-mail reminder still goes as before).'],
        'balance_reminder' => ['Balance reminder', true,
            "Hello {first_name}, a friendly reminder that {balance} is still due on order {order} with {house}.{pay_line}\n\nThank you.",
            'For ready or handed-over orders with money still owed: first after the days below, then repeated.'],
        'appointment_booked' => ['Appointment booked', true,
            "Hello {first_name}, your {appointment_type} at {house} is booked for {appointment_time} at {pickup}. Call us on {phone} if you need to change it.",
            'When staff book an appointment (consultation, measurement, collection…).'],
        'appointment_reminder' => ['Appointment reminder', true,
            "Hello {first_name}, a reminder of your {appointment_type} tomorrow, {appointment_time}, at {pickup}. See you then! — {house}",
            'The day before an appointment.'],
        'birthday' => ['Birthday greeting', false,
            "Happy birthday, {first_name}! 🎉 Everyone at {house} wishes you a wonderful year ahead.",
            'On the customer’s birthday, around 9am. Not sent to customers who said no to promotions.'],
        'order_delivered' => ['Thank-you after handover', false,
            "Hello {first_name}, thank you for choosing {house}! We hope you love your {items}. We'd be glad to make your next outfit.",
            'When all garments have been handed over.'],
    ];

    public const PLACEHOLDERS = [
        '{first_name}' => 'Customer’s first name', '{house}' => 'Your house name', '{order}' => 'Order number',
        '{items}' => 'What was ordered', '{total}' => 'Order total', '{paid}' => 'Paid so far', '{balance}' => 'Still owed',
        '{balance_line}' => '“Balance on ORD-…: ₦…” or “fully paid”', '{amount}' => 'Payment amount', '{method}' => 'How they paid',
        '{receipt}' => 'Receipt number', '{pickup}' => 'Branch and address', '{phone}' => 'House phone',
        '{fitting_time}' => 'Fitting date and time', '{pay_line}' => 'Your bank details, if set in the online store',
        '{appointment_time}' => 'Appointment date and time', '{appointment_type}' => 'consultation, measurement…',
        '{due_line}' => '“Expected ready by …” when there’s a promised date', '{is_are}' => '“is” or “are”, to match {items}',
    ];

    public const CHANNEL_LABELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'];

    public function __construct(protected Messenger $messenger)
    {
    }

    /** Run a trigger without ever letting a messaging problem break the save that caused it. */
    public static function safely(callable $fn): void
    {
        try {
            $fn(app(self::class));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Customer update not queued', ['error' => $e->getMessage()]);
            report($e);
        }
    }

    /* ------------------------------ settings ------------------------------ */

    public function settings(?Tenant $tenant = null): array
    {
        $t = $tenant ?? CurrentTenant::require();
        $s = (array) $t->setting('customer_updates', []);
        $events = [];
        foreach (self::EVENTS as $key => [$label, $on, $text]) {
            $events[$key] = [
                'on' => (bool) ($s['events'][$key]['on'] ?? $on),
                'text' => trim((string) ($s['events'][$key]['text'] ?? '')) ?: $text,
                'custom' => ! empty(trim((string) ($s['events'][$key]['text'] ?? ''))),
            ];
        }

        return [
            'events'   => $events,
            'channels' => array_values(array_intersect($s['channels'] ?? ['whatsapp', 'sms', 'email'], array_keys(self::CHANNEL_LABELS))) ?: ['whatsapp', 'sms', 'email'],
            'first_only' => (bool) ($s['first_only'] ?? true),
            'balance_after_days' => (int) ($s['balance_after_days'] ?? 3),
            'balance_repeat_days' => (int) ($s['balance_repeat_days'] ?? 7),
            'balance_max' => (int) ($s['balance_max'] ?? 3),
        ];
    }

    /** $data: events[key][on|text], channels[], first_only, balance_after_days, balance_repeat_days, balance_max */
    public function saveSettings(array $data): void
    {
        $tenant = CurrentTenant::require();
        $events = [];
        foreach (self::EVENTS as $key => [, , $default]) {
            $text = trim((string) ($data['events'][$key]['text'] ?? ''));
            $events[$key] = [
                'on' => (bool) ($data['events'][$key]['on'] ?? false),
                // Keep only real changes, so improvements to the default wording still reach the house.
                'text' => $text !== '' && $this->normalise($text) !== $this->normalise($default) ? $text : null,
            ];
        }
        $settings = $tenant->settings ?? [];
        $settings['customer_updates'] = [
            'events' => $events,
            'channels' => array_values(array_unique(array_intersect($data['channels'] ?? [], array_keys(self::CHANNEL_LABELS)))),
            'first_only' => (bool) ($data['first_only'] ?? false),
            'balance_after_days' => (int) ($data['balance_after_days'] ?? 3),
            'balance_repeat_days' => (int) ($data['balance_repeat_days'] ?? 7),
            'balance_max' => (int) ($data['balance_max'] ?? 3),
        ];
        $tenant->update(['settings' => $settings]);
        AuditLogger::log('customer_updates.settings_changed', $tenant, after: $settings['customer_updates']);
    }

    protected function normalise(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim(str_replace("\r", '', $s)));
    }

    public function enabled(string $event): bool
    {
        return $this->settings()['events'][$event]['on'] ?? false;
    }

    /* ------------------------------- triggers ------------------------------ */

    /** Called for every order status change. */
    public function orderStatusChanged(Order $order, string $to): void
    {
        match ($to) {
            'confirmed' => $this->orderUpdate($order, 'order_confirmed'),
            'ready'     => $this->orderUpdate($order, 'order_ready'),
            'delivered' => $this->orderUpdate($order, 'order_delivered'),
            default     => null,
        };
    }

    protected function orderUpdate(Order $order, string $event): ?OutgoingMessage
    {
        if (! $this->enabled($event) || ! $order->customer_id) {
            return null;
        }
        // A cancelled-then-reopened or re-readied order doesn't tell the customer twice.
        return $this->queue($order->customer, $event, $this->render($event, $this->orderVars($order)), $order, "{$event}:{$order->id}");
    }

    public function paymentReceived(Payment $payment): ?OutgoingMessage
    {
        if (! $this->enabled('payment_received') || ! $payment->customer_id || $payment->sale_id || $payment->status !== 'confirmed') {
            return null;
        }
        $order = $this->orderForPayment($payment);
        $vars = ($order ? $this->orderVars($order) : $this->houseVars()) + [
            '{amount}' => Money::format($payment->amount), '{method}' => mb_strtolower($payment->method_label ?? $payment->method),
            '{receipt}' => $payment->number,
        ];
        if (! $order) {
            $vars['{balance_line}'] = 'Thank you.';
        }

        return $this->queue($payment->customer, 'payment_received', $this->render('payment_received', $vars), $order ?? $payment, "payment_received:{$payment->id}");
    }

    /** SMS / WhatsApp for a fitting reminder (the e-mail reminder is sent by FittingService as before). */
    public function fittingReminder(Fitting $f, int $reminderId): ?OutgoingMessage
    {
        if (! $this->enabled('fitting_reminder') || ! $f->customer) {
            return null;
        }
        $tz = $this->tz();
        $vars = ($f->order ? $this->orderVars($f->order) : $this->houseVars($f->branch)) + [
            '{fitting_time}' => $f->scheduled_at->timezone($tz)->format('l j F, g:ia'),
        ];
        if ($f->branch) {
            $vars['{pickup}'] = $this->pickup($f->branch);
        }
        $channels = array_values(array_diff($this->settings()['channels'], ['email']));

        return $channels ? $this->queue($f->customer, 'fitting_reminder', $this->render('fitting_reminder', $vars), $f, "fitting_reminder:{$reminderId}", channels: $channels) : null;
    }

    /** Confirmation now, and a reminder the day before (held in the outbox until then). */
    public function appointmentBooked(\App\Models\Appointment $a): void
    {
        $this->cancelFor($a);
        $vars = $this->appointmentVars($a);
        $stamp = $a->starts_at->timestamp;
        if ($this->enabled('appointment_booked')) {
            $this->queue($a->customer, 'appointment_booked', $this->render('appointment_booked', $vars), $a, "appointment_booked:{$a->id}:{$stamp}");
        }
        $remindAt = CarbonImmutable::parse($a->starts_at)->subDay();
        if ($this->enabled('appointment_reminder') && $remindAt->isFuture()) {
            $this->queue($a->customer, 'appointment_reminder', $this->render('appointment_reminder', $vars), $a, "appointment_reminder:{$a->id}:{$stamp}", sendAfter: $remindAt);
        }
    }

    /** Stop anything still waiting about this (e.g. the reminder of a moved or cancelled appointment). */
    public function cancelFor(Model $related): int
    {
        return OutgoingMessage::where('related_type', $related->getMorphClass())->where('related_id', $related->getKey())
            ->where('status', 'queued')->update(['status' => 'cancelled', 'result' => 'No longer needed.']);
    }

    protected function appointmentVars(\App\Models\Appointment $a): array
    {
        $a->loadMissing('customer', 'branch', 'order');

        $vars = $a->order ? $this->orderVars($a->order) : $this->houseVars($a->branch) + ['{first_name}' => $a->customer?->first_name ?? 'there'];
        $vars['{appointment_time}'] = $a->starts_at->copy()->timezone($this->tz())->format('l j F, g:ia');
        $vars['{appointment_type}'] = mb_strtolower($a->type === 'other' ? 'appointment' : $a->type_label);
        if ($a->branch) {
            $vars['{pickup}'] = $this->pickup($a->branch);
            $vars['{phone}'] = $a->branch->phone ?: $vars['{phone}'];
        }

        return $vars;
    }

    /** Scheduled: nudge customers about money owed on ready / handed-over orders. Returns how many were queued. */
    public function balanceReminders(?CarbonImmutable $now = null): int
    {
        if (! $this->enabled('balance_reminder')) {
            return 0;
        }
        $s = $this->settings();
        $tz = $this->tz();
        $now = ($now ?? CarbonImmutable::now())->timezone($tz);
        if ($now->hour < 9 || $now->hour >= 19) {
            return 0;
        }
        $n = 0;
        Order::with('customer', 'branch', 'lines')->whereIn('status', ['ready', 'delivered'])->whereNotNull('customer_id')
            ->orderBy('id')->chunk(200, function ($orders) use ($s, $now, &$n) {
                foreach ($orders as $order) {
                    if ($order->balance() <= 0.004) {
                        continue;
                    }
                    $sent = OutgoingMessage::where('purpose', 'balance_reminder')->where('related_type', $order->getMorphClass())->where('related_id', $order->id)
                        ->whereIn('status', ['queued', 'sending', 'sent'])->orderByDesc('id')->get(['id', 'created_at']);
                    if ($sent->count() >= $s['balance_max']) {
                        continue;
                    }
                    $due = $sent->isEmpty()
                        ? $this->readySince($order)?->addDays($s['balance_after_days'])
                        : CarbonImmutable::parse($sent->first()->created_at)->addDays($s['balance_repeat_days']);
                    if (! $due || $due->gt($now)) {
                        continue;
                    }
                    if ($this->queue($order->customer, 'balance_reminder', $this->render('balance_reminder', $this->orderVars($order)), $order,
                        'balance_reminder:' . $order->id . ':' . ($sent->count() + 1))) {
                        $n++;
                    }
                }
            });

        return $n;
    }

    /** Scheduled (hourly, from 9am): happy birthday to today's birthdays. */
    public function birthdays(?CarbonImmutable $now = null): int
    {
        if (! $this->enabled('birthday')) {
            return 0;
        }
        $now = ($now ?? CarbonImmutable::now())->timezone($this->tz());
        if ($now->hour < 9 || $now->hour >= 19) {
            return 0;
        }
        $n = 0;
        Customer::active()->whereNotNull('birthday')->get(['id', 'tenant_id', 'first_name', 'birthday', 'phone', 'whatsapp', 'email', 'status'])
            ->filter(fn ($c) => $c->birthday->month === $now->month && $c->birthday->day === $now->day)
            ->each(function ($c) use ($now, &$n) {
                if ($this->queue($c, 'birthday', $this->render('birthday', $this->houseVars() + ['{first_name}' => $c->first_name]), $c, "birthday:{$c->id}:{$now->year}")) {
                    $n++;
                }
            });

        return $n;
    }

    /** A message a staff member writes (or picks from the house's wording) on an order or a customer. */
    public function custom(Customer $customer, string $text, ?Model $related, User $by, ?array $channels = null): OutgoingMessage
    {
        return $this->queue($customer, 'custom', trim($text), $related, null, $by, $channels, force: true);
    }

    /** The house's wording for an event, filled in for this order — for staff to send or edit. */
    public function draftFor(Order $order, string $event): string
    {
        return $this->render($event, $this->orderVars($order));
    }

    /* -------------------------------- outbox -------------------------------- */

    /** Put a message in the outbox and send it as soon as the page has responded. Null if it was already sent. */
    public function queue(Customer $customer, string $purpose, string $text, ?Model $related = null, ?string $dedupe = null,
        ?User $by = null, ?array $channels = null, bool $force = false, ?CarbonImmutable $sendAfter = null): ?OutgoingMessage
    {
        if (! $force && $customer->status === 'merged') {
            return null;
        }
        $s = $this->settings();
        $tenant = CurrentTenant::require();
        try {
            $msg = OutgoingMessage::create([
                'customer_id' => $customer->id, 'purpose' => $purpose,
                'related_type' => $related?->getMorphClass(), 'related_id' => $related?->getKey(),
                'subject' => ($purpose === 'custom' ? 'A message' : (self::EVENTS[$purpose][0] ?? 'Update')) . ' from ' . $tenant->name,
                'body' => $text, 'channels' => $channels ?: $s['channels'], 'first_only' => $s['first_only'],
                'status' => 'queued', 'send_after' => $sendAfter?->utc(), 'dedupe_key' => $dedupe, 'created_by' => $by?->id ?? auth()->id(),
            ]);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '23000') || str_contains($e->getMessage(), '23505')) {
                return null;   // already queued or sent
            }
            throw $e;
        }
        if (! $sendAfter) {
            $id = $msg->id;
            // After the response (and after the surrounding transaction): never slows the page, never sends for a rolled-back save.
            DB::afterCommit(fn () => defer(fn () => CurrentTenant::run($tenant, fn () => $this->deliver(OutgoingMessage::find($id)))));
        }

        return $msg;
    }

    /** Send one outbox message now. Safe to call twice: only one caller claims it. */
    public function deliver(?OutgoingMessage $msg): ?OutgoingMessage
    {
        if (! $msg || ! OutgoingMessage::whereKey($msg->id)->where('status', 'queued')->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])) {
            return $msg;
        }
        $msg->refresh();
        $customer = $msg->customer;
        if (! $customer) {
            $msg->update(['status' => 'cancelled', 'result' => 'Customer no longer exists.']);

            return $msg;
        }
        $tenant = CurrentTenant::require();
        $template = Messenger::settings($tenant)['whatsapp_update_template'];
        $logs = $this->messenger->send($customer, $msg->channels, $msg->subject, $msg->body, [
            'purpose' => $msg->purpose, 'related' => $msg->related, 'by' => $msg->creator, 'first' => $msg->first_only,
            'outgoing' => $msg->id, 'whatsapp_template' => $template,
            'consent' => match ($msg->purpose) { 'campaign' => 'marketing', 'birthday' => 'marketing_soft', default => 'transactional' },
            'whatsapp_params' => $template ? [$customer->first_name, preg_replace('/\s+/', ' ', $msg->body), $tenant->name] : null,
        ]);
        $sent = $logs->where('status', 'sent');
        if ($sent->isNotEmpty()) {
            $msg->update(['status' => 'sent', 'sent_at' => now(), 'result' => $sent->pluck('channel')->map(fn ($c) => self::CHANNEL_LABELS[$c])->implode(', ')]);
        } else {
            // Try again later only if something actually failed (not just "no phone number" / "not set up").
            $retry = $logs->contains('status', 'failed') && $msg->attempts < 3;
            $msg->update([
                'status' => $retry ? 'queued' : 'failed', 'send_after' => $retry ? now()->addMinutes(10 * $msg->attempts) : $msg->send_after,
                'result' => mb_substr($logs->map(fn ($l) => self::CHANNEL_LABELS[$l->channel] . ': ' . $l->error)->implode(' · ') ?: 'No channel to send on.', 0, 255),
            ]);
        }

        return $msg;
    }

    /** Scheduled: send what is waiting (retries, delayed messages, anything a page didn't get to). */
    public function sendWaiting(): int
    {
        $n = 0;
        OutgoingMessage::where('status', 'queued')->where(fn ($q) => $q->whereNull('send_after')->orWhere('send_after', '<=', now()))
            // Not stale: due within the last day, or (no set time) created in the last three days.
            ->where(fn ($q) => $q->where('send_after', '>=', now()->subDay())->orWhere(fn ($w) => $w->whereNull('send_after')->where('created_at', '>=', now()->subDays(3))))
            ->orderBy('id')->limit(200)->get()
            ->each(function ($m) use (&$n) {
                if ($this->deliver($m)?->status === 'sent') {
                    $n++;
                }
            });
        // Stuck mid-send for over 15 minutes (the request died): try again.
        OutgoingMessage::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(15))->where('attempts', '<', 3)->update(['status' => 'queued']);

        return $n;
    }

    /** Messages about an order (and its payments and fittings), newest first. */
    public function forOrder(Order $order): Collection
    {
        $paymentIds = \App\Models\PaymentAllocation::where('order_id', $order->id)->pluck('payment_id');

        return OutgoingMessage::with('creator:id,name', 'logs')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('related_type', $order->getMorphClass())->where('related_id', $order->id))
                ->orWhere(fn ($w) => $w->where('related_type', (new Payment)->getMorphClass())->whereIn('related_id', $paymentIds))
                ->orWhere(fn ($w) => $w->where('related_type', (new Fitting)->getMorphClass())->whereIn('related_id', $order->fittings()->pluck('id'))))
            ->latest('id')->limit(30)->get();
    }

    /* ------------------------------- wording ------------------------------- */

    public function render(string $event, array $vars): string
    {
        $text = $this->settings()['events'][$event]['text'] ?? (self::EVENTS[$event][2] ?? '');
        $out = strtr($text, $vars);
        // Unknown placeholders and empty lines left by blank values.
        $out = preg_replace('/\{[a-z_]+\}/', '', $out);

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $out)));
    }

    /** Sample values, for the settings page preview. */
    public function sample(string $event): string
    {
        $t = CurrentTenant::require();
        $phone = $t->phone ?: '0803 000 0000';

        return strtr($this->settings()['events'][$event]['text'] ?? '', [
            '{first_name}' => 'Ada', '{house}' => $t->name, '{order}' => 'ORD-2026-00042', '{items}' => 'agbada and cap', '{is_are}' => 'are',
            '{total}' => Money::format(185000), '{paid}' => Money::format(100000), '{balance}' => Money::format(85000),
            '{balance_line}' => 'Balance on ORD-2026-00042: ' . Money::format(85000) . '.', '{amount}' => Money::format(50000),
            '{method}' => 'bank transfer', '{receipt}' => 'RCP-2026-00108', '{pickup}' => $t->name . ($t->address ? ', ' . $t->address : ''),
            '{phone}' => $phone, '{fitting_time}' => now($this->tz())->addDays(2)->setTime(11, 0)->format('l j F, g:ia'),
            '{appointment_time}' => now($this->tz())->addDays(3)->setTime(14, 0)->format('l j F, g:ia'), '{appointment_type}' => 'consultation',
            '{pay_line}' => $this->payLine($t) ?: '', '{due_line}' => ' Expected ready by ' . now($this->tz())->addDays(14)->format('j M') . '.',
        ]);
    }

    protected function houseVars($branch = null): array
    {
        $t = CurrentTenant::require();

        return [
            '{house}' => $t->name, '{phone}' => $branch?->phone ?: ($t->phone ?: ''), '{pickup}' => $this->pickup($branch),
            '{pay_line}' => $this->payLine($t),
        ];
    }

    public function orderVars(Order $order): array
    {
        $order->loadMissing('customer', 'branch', 'lines');
        $balance = max(0, $order->balance());
        $items = $order->lines->where('status', '!=', 'cancelled')->pluck('description')->filter()->unique()->values();
        $itemsText = match (true) {
            $items->isEmpty() => $order->title ?: 'order',
            $items->count() <= 3 => $items->count() === 1 ? $items[0] : $items->slice(0, -1)->implode(', ') . ' and ' . $items->last(),
            default => $items->count() . ' garments',
        };
        $tz = $this->tz();

        return $this->houseVars($order->branch) + [
            '{first_name}' => $order->customer?->first_name ?? 'there', '{order}' => $order->number, '{items}' => mb_strtolower($itemsText),
            '{is_are}' => $items->count() > 1 ? 'are' : 'is',
            '{total}' => Money::format($order->chargeable()), '{paid}' => Money::format($order->paid()), '{balance}' => Money::format($balance),
            '{balance_line}' => $balance > 0.004 ? "Balance on {$order->number}: " . Money::format($balance) . '.' : "Your order {$order->number} is fully paid. Thank you!",
            '{due_line}' => $order->promised_date ? ' Expected ready by ' . CarbonImmutable::parse($order->promised_date)->format('j M') . '.' : '',
        ];
    }

    protected function pickup($branch): string
    {
        $t = CurrentTenant::require();
        if ($branch) {
            return $branch->name . ($branch->address ? ', ' . $branch->address : '');
        }

        return $t->name . ($t->address ? ', ' . $t->address : '');
    }

    protected function payLine(Tenant $t): string
    {
        $b = (array) StoreSettings::get($t, 'bank', []);

        return ! empty($b['account_number'])
            ? ' You can pay by transfer to ' . trim(($b['bank_name'] ?? '') . ' ' . $b['account_number']) . (! empty($b['account_name']) ? ' (' . $b['account_name'] . ')' : '') . '.'
            : '';
    }

    protected function orderForPayment(Payment $p): ?Order
    {
        $alloc = \App\Models\PaymentAllocation::with('order', 'invoice')->where('payment_id', $p->id)->orderByDesc('amount')->first();

        return $alloc?->order ?? ($alloc?->invoice?->order_id ? Order::find($alloc->invoice->order_id) : null);
    }

    protected function readySince(Order $order): ?CarbonImmutable
    {
        $at = OrderStatusEvent::where('order_id', $order->id)->whereIn('to_status', ['ready', 'delivered'])->min('created_at') ?? $order->delivered_at;

        return $at ? CarbonImmutable::parse($at) : null;
    }

    protected function tz(): string
    {
        return CurrentTenant::get()?->timezone ?: config('app.timezone');
    }
}
