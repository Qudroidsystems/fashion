<?php

namespace App\Services\Crm;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\FollowUpTask;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Duplicate detection, merging and the customer timeline (SRS FR-CRM-002, FR-CRM-003). */
class CustomerService
{
    /**
     * Possible duplicates in the current tenant: same phone (last 10 digits),
     * same e-mail, or same first + last name. Configurable rules can be added
     * here later (FR-CRM-002 "configurable matching rules").
     */
    public function findDuplicates(array $data, ?int $ignoreId = null): Collection
    {
        $phone = Customer::normalizePhone($data['phone'] ?? null);
        $email = ! empty($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));

        if (! $phone && ! $email && ! ($first && $last)) {
            return collect();
        }

        return Customer::active()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($phone, $email, $first, $last) {
                if ($phone) {
                    $q->orWhere('phone_normalized', $phone);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($first && $last) {
                    $q->orWhere(fn ($w) => $w->where('first_name', $first)->where('last_name', $last));
                }
            })
            ->limit(5)
            ->get()
            ->map(function (Customer $c) use ($phone, $email, $first, $last) {
                $c->match_reasons = array_values(array_filter([
                    $phone && $c->phone_normalized === $phone ? 'phone' : null,
                    $email && $c->email === $email ? 'e-mail' : null,
                    $first && $last && mb_strtolower($c->first_name) === mb_strtolower($first) && mb_strtolower((string) $c->last_name) === mb_strtolower($last) ? 'name' : null,
                ]));

                return $c;
            });
    }

    /**
     * Merge $source into $target: notes, follow-ups, tags and consents move
     * to the target; empty target fields are filled from the source; the
     * source is kept (status "merged", pointing at the target) for history.
     */
    public function merge(Customer $source, Customer $target): Customer
    {
        if ($source->is($target)) {
            throw new \InvalidArgumentException('A customer cannot be merged into itself.');
        }

        return DB::transaction(function () use ($source, $target) {
            $before = ['source' => $source->only(['customer_number', 'first_name', 'last_name', 'phone', 'email'])];

            CustomerNote::where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            FollowUpTask::where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            $target->tags()->syncWithoutDetaching($source->tags()->pluck('customer_tags.id'));

            // Consent: keep the target's answer where it has one, else take the source's.
            foreach ($source->consents as $consent) {
                CustomerConsent::firstOrCreate(
                    ['customer_id' => $target->id, 'channel' => $consent->channel, 'purpose' => $consent->purpose],
                    $consent->only(['granted', 'source', 'recorded_at', 'recorded_by'])
                );
            }

            foreach (['last_name', 'gender', 'phone', 'alt_phone', 'whatsapp', 'email', 'birthday', 'address', 'city', 'state', 'occupation', 'source', 'branch_id', 'assigned_to'] as $field) {
                if (blank($target->{$field}) && filled($source->{$field})) {
                    $target->{$field} = $source->{$field};
                }
            }
            $target->is_vip = $target->is_vip || $source->is_vip;
            $target->preferences = array_merge($source->preferences ?? [], $target->preferences ?? []) ?: null;
            $target->save();

            $source->forceFill(['status' => 'merged', 'merged_into_id' => $target->id])->save();

            CustomerNote::create([
                'customer_id' => $target->id,
                'body'        => "Merged duplicate record {$source->customer_number} ({$source->name}) into this customer.",
                'created_by'  => auth()->id(),
            ]);

            AuditLogger::log('customer.merged', $target, $before, ['target' => $target->only(['customer_number', 'first_name', 'last_name'])]);

            return $target->fresh();
        });
    }

    /**
     * Customer timeline (FR-CRM-003): notes, follow-ups and audit events,
     * newest first. Orders, measurements, payments and appointments are
     * added here as those modules land.
     */
    public function timeline(Customer $customer, ?User $viewer, int $limit = 50): Collection
    {
        $items = collect();

        CustomerNote::where('customer_id', $customer->id)->visibleTo($viewer)->with('author:id,name')
            ->latest()->limit($limit)->get()
            ->each(fn ($n) => $items->push([
                'type' => 'note', 'icon' => $n->is_sensitive ? 'ri-lock-2-line' : 'ri-sticky-note-line',
                'title' => $n->is_sensitive ? 'Sensitive note' : 'Note', 'body' => $n->body,
                'by' => $n->author?->name, 'at' => $n->created_at,
            ]));

        FollowUpTask::where('customer_id', $customer->id)->with('assignee:id,name')
            ->latest()->limit($limit)->get()
            ->each(fn ($t) => $items->push([
                'type' => 'follow_up', 'icon' => $t->status === 'done' ? 'ri-checkbox-circle-line' : 'ri-alarm-line',
                'title' => 'Follow-up: ' . $t->title . ($t->status !== 'open' ? ' (' . $t->status . ')' : ''),
                'body' => $t->details, 'by' => $t->assignee?->name,
                'at' => $t->completed_at ?? $t->created_at,
            ]));

        AuditLog::where('auditable_type', $customer->getMorphClass())->where('auditable_id', $customer->id)
            ->with('user:id,name')->latest('created_at')->limit($limit)->get()
            ->each(fn ($a) => $items->push([
                'type' => 'event', 'icon' => 'ri-history-line',
                'title' => \Illuminate\Support\Str::headline(str_replace(['customer.', '.'], ['', ' '], $a->action)),
                'body' => $a->description, 'by' => $a->user?->name, 'at' => $a->created_at,
            ]));

        return $items->sortByDesc('at')->values()->take($limit);
    }

    /** Find-or-create tags by name and attach exactly these to the customer. */
    public function syncTags(Customer $customer, array $names): void
    {
        $ids = collect($names)
            ->map(fn ($n) => trim((string) $n))->filter()->unique(fn ($n) => mb_strtolower($n))->take(15)
            ->map(fn ($n) => CustomerTag::firstOrCreate(['name' => mb_substr($n, 0, 40)])->id);

        $customer->tags()->sync($ids);
    }

    /** Save consent answers: ['sms' => ['transactional' => true, 'marketing' => false], …] */
    public function syncConsents(Customer $customer, array $answers, string $source = 'staff'): void
    {
        foreach (CustomerConsent::CHANNELS as $channel => $label) {
            foreach (CustomerConsent::PURPOSES as $purpose => $plabel) {
                if (! array_key_exists($channel, $answers) || ! array_key_exists($purpose, $answers[$channel] ?? [])) {
                    continue;
                }
                $granted = (bool) $answers[$channel][$purpose];
                $existing = CustomerConsent::where(['customer_id' => $customer->id, 'channel' => $channel, 'purpose' => $purpose])->first();

                if ($existing && $existing->granted === $granted) {
                    continue;
                }

                CustomerConsent::updateOrCreate(
                    ['customer_id' => $customer->id, 'channel' => $channel, 'purpose' => $purpose],
                    ['granted' => $granted, 'source' => $source, 'recorded_at' => now(), 'recorded_by' => auth()->id()]
                );

                AuditLogger::log('customer.consent_changed', $customer,
                    ['consent' => "$channel/$purpose: " . ($existing ? ($existing->granted ? 'yes' : 'no') : 'not asked')],
                    ['consent' => "$channel/$purpose: " . ($granted ? 'yes' : 'no')]);
            }
        }
    }
}
