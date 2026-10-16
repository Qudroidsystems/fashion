<?php

namespace App\Services\Production;

use App\Models\Order;
use App\Models\ProductionTask;
use App\Models\User;
use App\Notifications\DeliveryAtRisk;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Late-delivery risk (SRS FR-PRD-009, §10). Plain rules, each with a reason
 * people can read and act on:
 *   - promise date passed;
 *   - remaining planned work (hours, longest garment) vs working days left;
 *   - tasks past their due date, or due soon with nobody on them;
 *   - the people assigned already carry more open tasks than the house limit;
 *   - production not started close to the promise date.
 * "high" when the date cannot be met as planned, "watch" when slack is thin.
 */
class RiskService
{
    public function __construct(protected ProductionService $production)
    {
    }

    /** @return array{level: ?string, reasons: array<int, array{level: string, text: string}>} */
    public function assess(Order $order): array
    {
        $reasons = [];
        $add = function (string $level, string $text) use (&$reasons) {
            $reasons[] = ['level' => $level, 'text' => $text];
        };
        if (! in_array($order->status, OrderStatus::OPEN, true) || ! $order->promised_date) {
            return ['level' => null, 'reasons' => []];
        }
        $left = $this->production->workingDaysUntil($order->promised_date);
        $tasks = ProductionTask::where('order_id', $order->id)->open()->get();

        if ($left < 0 || ($left === 0 && $order->status !== 'ready')) {
            $add('high', $left < 0 ? 'The promise date has passed.' : 'Due today and not ready.');
        } elseif ($order->status !== 'ready') {
            if (! ProductionTask::where('order_id', $order->id)->exists()) {
                if ($left <= 5) {
                    $add('watch', "Production has not been planned and the promise date is {$left} working day(s) away.");
                }
            } else {
                // Garments are made in parallel; each garment's stages in sequence.
                $hours = $tasks->groupBy(fn ($t) => $t->order_line_id ?? 0)
                    ->map(fn ($g) => $g->sum(fn ($t) => $t->status === 'in_progress' ? $t->duration_hours / 2 : $t->duration_hours))->max() ?? 0;
                $need = (int) ceil($hours / 8);
                if ($need > $left) {
                    $add('high', "About {$need} working day(s) of work left, {$left} until the promise date.");
                } elseif ($need > 0 && $left - $need <= 1) {
                    $add('watch', "Only " . ($left - $need) . " spare working day(s): {$need} day(s) of work, {$left} left.");
                }
            }
        }

        $today = now(CurrentTenant::get()->timezone)->toDateString();
        $overdue = $tasks->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today);
        if ($overdue->isNotEmpty()) {
            $add($overdue->count() > 1 ? 'high' : 'watch', $overdue->count() . ' task(s) past their due date: ' . $overdue->pluck('name')->unique()->take(3)->implode(', ') . '.');
        }
        $soon = $this->production->addWorkingDays(now(CurrentTenant::get()->timezone)->startOfDay(), 2)->toDateString();
        $unassigned = $tasks->filter(fn ($t) => ! $t->assigned_to && $t->status !== 'blocked' && $t->due_date && $t->due_date->toDateString() <= $soon);
        if ($unassigned->isNotEmpty()) {
            $add('watch', $unassigned->count() . ' task(s) due within two working days have nobody assigned.');
        }
        $limit = (int) CurrentTenant::get()->setting('production_task_limit', 12);
        if ($limit > 0) {
            $people = $tasks->pluck('assigned_to')->filter()->unique();
            if ($people->isNotEmpty()) {
                $loads = ProductionTask::open()->whereIn('assigned_to', $people)->selectRaw('assigned_to, count(*) c')->groupBy('assigned_to')->pluck('c', 'assigned_to');
                foreach ($loads as $uid => $c) {
                    if ($c > $limit) {
                        $add('watch', (User::find($uid)?->name ?? 'Someone') . " has {$c} open tasks (limit {$limit}).");
                    }
                }
            }
        }

        $level = collect($reasons)->contains('level', 'high') ? 'high' : ($reasons ? 'watch' : null);

        return ['level' => $level, 'reasons' => $reasons];
    }

    /**
     * Store the assessment. People are told only when the level goes up
     * (none → watch → high), so a daily run doesn't repeat itself.
     */
    public function refresh(Order $order, bool $notify = true): array
    {
        $a = $this->assess($order);
        $rank = ['' => 0, 'watch' => 1, 'high' => 2];
        $was = (string) $order->risk_notified_level;
        $order->forceFill(['risk_level' => $a['level'], 'risk_reasons' => $a['reasons'] ?: null, 'risk_assessed_at' => now()]);
        if ($notify && $rank[(string) $a['level']] > $rank[$was]) {
            $order->risk_notified_level = $a['level'];
            $people = User::inCurrentTenant()->get()->filter(fn ($u) => $u->can('Manage production workflow'))
                ->push($order->owner)->filter()->unique('id');
            DB::afterCommit(fn () => $people->each(fn ($u) => $u->notify(new DeliveryAtRisk($order, $a))));
        } elseif ($rank[(string) $a['level']] < $rank[$was]) {
            $order->risk_notified_level = $a['level'];   // re-arm when it improves
        }
        $order->saveQuietly();

        return $a;
    }

    public function refreshAll(): int
    {
        $n = 0;
        Order::whereIn('status', OrderStatus::OPEN)->whereNotNull('promised_date')->each(function ($o) use (&$n) {
            $this->refresh($o);
            $n++;
        });
        Order::whereNotIn('status', OrderStatus::OPEN)->whereNotNull('risk_level')->update(['risk_level' => null, 'risk_reasons' => null, 'risk_notified_level' => null]);

        return $n;
    }
}
