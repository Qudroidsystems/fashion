<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\WeeklySummaryNotice;
use App\Services\Reports\Definitions\ReceivablesReport;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * Monday-morning summary of last week (SRS §15 "scheduled reports"), sent to
 * the owner and anyone who can see financial reports — each with the figures
 * their own branch limit allows. Uses the same report definitions as the
 * screens. Sent once per house per week.
 */
class WeeklySummary
{
    /** Figures for last Monday–Sunday in the house's time zone, as $user may see them. */
    public function figures(User $user): array
    {
        $tz = CurrentTenant::get()->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($tz);
        $from = $now->subWeek()->startOfWeek();
        $to = $from->endOfWeek();
        $f = ReportFilters::make(['period' => 'custom', 'from' => $from->toDateString(), 'to' => $to->toDateString()], $user);
        $facts = new FinanceFacts($f);
        $rec = app(ReceivablesReport::class)->run(ReportFilters::make([], $user), $user);
        $orders = Order::query()->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId));

        return [
            'label'      => $f->label(),
            'collected'  => $facts->collected(),
            'earned'     => $facts->recognised(),
            'expenses'   => $facts->expenses(),
            'owed'       => (float) $rec->cards[0]['value'],
            'handed'     => (float) $rec->cards[1]['value'],
            'new_orders' => (clone $orders)->whereBetween('confirmed_at', [$f->fromUtc(), $f->toUtc()])->count(),
            'delivered'  => $facts->deliveredOrders()->count(),
            'overdue'    => (clone $orders)->whereIn('status', ['confirmed', 'in_production', 'fitting'])->whereNotNull('promised_date')
                ->whereDate('promised_date', '<', $now->toDateString())->count(),
            'due_week'   => (clone $orders)->whereIn('status', OrderStatus::OPEN)->whereBetween('promised_date', [$now->toDateString(), $now->addDays(6)->toDateString() . ' 23:59:59'])->count(),
            'week_key'   => $from->toDateString(),
        ];
    }

    /** People who get it: active owners and members who can see financial reports and reports. */
    public function recipients(): \Illuminate\Support\Collection
    {
        return TenantMembership::where('status', 'active')->with('user')->get()->pluck('user')->filter()
            ->filter(fn (User $u) => ! $u->is_disabled && ($u->isOwnerOfCurrentTenant() || ($u->can('View financial reports') && $u->can('View reports'))))
            ->values();
    }

    /** Send for one house unless already sent this week. Returns how many people got it. */
    public function sendFor(Tenant $tenant, bool $force = false): int
    {
        return CurrentTenant::run($tenant, function () use ($tenant, $force) {
            $week = CarbonImmutable::now($tenant->timezone ?: config('app.timezone'))->subWeek()->startOfWeek()->toDateString();
            $settings = $tenant->settings ?? [];
            if (! $force && ($settings['reports']['weekly_summary_sent'] ?? null) === $week) {
                return 0;
            }
            if (($settings['reports']['weekly_summary'] ?? true) === false) {
                return 0;
            }
            $n = 0;
            foreach ($this->recipients() as $user) {
                $user->notify(new WeeklySummaryNotice($tenant, $this->figures($user)));
                $n++;
            }
            $settings['reports']['weekly_summary_sent'] = $week;
            $tenant->forceFill(['settings' => $settings])->saveQuietly();

            return $n;
        });
    }
}
