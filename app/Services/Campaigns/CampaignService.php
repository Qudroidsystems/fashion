<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutgoingMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Messaging\CustomerUpdates;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Campaigns: pick a group, write one message, send now or later. Only customers who said yes to
 * promotions on a channel are messaged on it (Messenger checks again when each one goes), and each
 * message ends with how to stop them. Messages go out through the outbox, a few hundred a minute.
 */
class CampaignService
{
    public const FOOTER = "\n\nTo stop these messages, reply STOP or let us know.";

    public function __construct(protected CustomerUpdates $updates)
    {
    }

    /** Customers in the group (before checking consent). */
    public function audience(array $a): Builder
    {
        $tz = CurrentTenant::get()?->timezone ?: config('app.timezone');
        $days = max(1, (int) ($a['days'] ?? 90));
        $since = now($tz)->subDays($days)->startOfDay()->utc();

        return Customer::active()->when(($a['type'] ?? 'all') !== 'all', fn ($q) => match ($a['type']) {
            'vip'            => $q->where('is_vip', true),
            'tags'           => $q->whereHas('tags', fn ($t) => $t->whereIn('customer_tags.id', (array) ($a['tag_ids'] ?? []))),
            'birthday_month' => $q->whereNotNull('birthday')->whereMonth('birthday', now($tz)->month),
            'recent'         => $q->whereIn('id', Order::where('created_at', '>=', $since)->whereNotIn('status', ['draft', 'cancelled'])->select('customer_id')),
            'lapsed'         => $q->whereIn('id', Order::whereNotIn('status', ['draft', 'cancelled'])->select('customer_id'))
                                  ->whereNotIn('id', Order::where('created_at', '>=', $since)->select('customer_id')),
            default          => $q,
        });
    }

    /** Who can actually be reached: customers in the group with a yes to promotions on one of the channels and an address for it. */
    public function reachable(array $audience, array $channels): Builder
    {
        return $this->audience($audience)->whereHas('consents', fn ($q) => $q->where('purpose', 'marketing')->where('granted', true)->whereIn('channel', $channels))
            ->where(function ($q) use ($channels) {
                foreach ($channels as $ch) {
                    match ($ch) {
                        'email'    => $q->orWhere(fn ($w) => $w->whereNotNull('email')->whereHas('consents', fn ($c) => $c->where(['purpose' => 'marketing', 'channel' => 'email', 'granted' => true]))),
                        'sms'      => $q->orWhere(fn ($w) => $w->whereNotNull('phone')->whereHas('consents', fn ($c) => $c->where(['purpose' => 'marketing', 'channel' => 'sms', 'granted' => true]))),
                        'whatsapp' => $q->orWhere(fn ($w) => $w->where(fn ($p) => $p->whereNotNull('whatsapp')->orWhereNotNull('phone'))->whereHas('consents', fn ($c) => $c->where(['purpose' => 'marketing', 'channel' => 'whatsapp', 'granted' => true]))),
                        default    => null,
                    };
                }
            });
    }

    public function counts(array $audience, array $channels): array
    {
        return ['in_group' => $this->audience($audience)->count(), 'reachable' => $channels ? $this->reachable($audience, $channels)->count() : 0];
    }

    /** Send now (or at send_at): one outbox message per reachable customer. */
    public function launch(Campaign $c, User $by): Campaign
    {
        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => 'This campaign has already gone out.']);
        }
        $when = $c->send_at && $c->send_at->isFuture() ? CarbonImmutable::parse($c->send_at) : CarbonImmutable::now();
        $house = CurrentTenant::require()->name;
        $n = 0;
        $this->reachable($c->audience, $c->channels)->orderBy('id')->chunk(500, function ($customers) use ($c, $by, $when, $house, &$n) {
            foreach ($customers as $cust) {
                $text = trim(strtr($c->body, ['{first_name}' => $cust->first_name, '{house}' => $house])) . self::FOOTER;
                // A time is always given, so the outbox sends them steadily instead of all inside this request.
                if ($this->updates->queue($cust, 'campaign', $text, $c, "campaign:{$c->id}:{$cust->id}", $by, $c->channels, sendAfter: $when)) {
                    $n++;
                }
            }
        });
        if ($n === 0) {
            throw ValidationException::withMessages(['audience' => 'Nobody in this group has agreed to promotions on these channels.']);
        }
        $c->update(['status' => $when->isFuture() ? 'scheduled' : 'sending', 'recipients' => $n, 'send_at' => $when->utc()]);
        AuditLogger::log('campaign.launched', $c, after: ['name' => $c->name, 'recipients' => $n, 'at' => $when->toIso8601String()]);

        return $c;
    }

    public function cancel(Campaign $c): Campaign
    {
        $stopped = $this->updates->cancelFor($c);
        $c->update(['status' => $c->status === 'draft' || $stopped === $c->recipients ? 'cancelled' : 'sent']);
        AuditLogger::log('campaign.cancelled', $c, after: ['not_sent' => $stopped]);

        return $c;
    }

    /** Progress: [queued, sent, failed, skipped/cancelled]. Marks the campaign sent once nothing is waiting. */
    public function progress(Campaign $c): array
    {
        $by = OutgoingMessage::where('related_type', $c->getMorphClass())->where('related_id', $c->id)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $p = ['waiting' => (int) (($by['queued'] ?? 0) + ($by['sending'] ?? 0)), 'sent' => (int) ($by['sent'] ?? 0), 'failed' => (int) ($by['failed'] ?? 0), 'cancelled' => (int) ($by['cancelled'] ?? 0)];
        if (in_array($c->status, ['sending', 'scheduled'], true) && $p['waiting'] === 0 && $c->recipients > 0) {
            $c->update(['status' => 'sent']);
        } elseif ($c->status === 'scheduled' && $c->send_at?->isPast()) {
            $c->update(['status' => 'sending']);
        }

        return $p;
    }
}
