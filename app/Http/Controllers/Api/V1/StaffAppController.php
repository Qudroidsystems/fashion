<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Fitting;
use App\Models\FollowUpTask;
use App\Models\Order;
use App\Models\ProductionTask;
use App\Models\TenantMembership;
use App\Services\Reports\DashboardFigures;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Endpoints the staff mobile app needs beyond the module APIs:
 *   GET  /dashboard                     — the house home (same figures and permissions as the web dashboard)
 *   GET  /staff                         — people in the house (for assigning fittings and work)
 *   GET  /fittings?from=&to=&status=    — fittings in a date range (house dates)
 *   GET  /notifications · POST /notifications/{id}/read · POST /notifications/read-all
 */
class StaffAppController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View dashboard', ['only' => ['dashboard']]);
        $this->middleware('permission:Manage fitting', ['only' => ['fittings']]);
    }

    /* ------------------------------- dashboard ------------------------------- */

    public function dashboard(Request $request): JsonResponse
    {
        $u = $request->user();
        $tz = CurrentTenant::get()->timezone ?: config('app.timezone');
        $now = Carbon::now($tz);
        $f = (new DashboardFigures($u))->all();
        $out = ['date' => $now->toDateString(), 'branch' => $f['branch'] ?? null];

        if (isset($f['money'])) {
            $m = $f['money'];
            $labels = $m['trend']['labels'] ?? [];
            $data = $m['trend']['data'] ?? [];
            $start = $now->copy()->subDays(max(0, count($data) - 1));
            $out['money'] = array_filter([
                'collected_today'  => $m['collected_today'] ?? 0,
                'collected_month'  => $m['collected_month'] ?? 0,
                'owed'             => $m['owed'] ?? null,
                'owed_handed_over' => $m['handed_unpaid'] ?? null,
                'deposits_held'    => $m['deposits'] ?? null,
                'sales_earned'     => $m['earned_month'] ?? null,
            ], fn ($v) => $v !== null);
            $out['chart'] = collect($data)->values()->map(fn ($v, $i) => [
                'date'   => $start->copy()->addDays($i)->toDateString(),
                'label'  => $labels[$i] ?? null,
                'amount' => (float) $v,
            ])->all();
        }

        if (isset($f['orders'])) {
            $o = $f['orders'];
            $out['orders'] = [
                'open'          => $o['open'],
                'due_this_week' => $o['due_week'],
                'late'          => $o['overdue'],
                'ready'         => $o['ready'],
                'at_risk'       => $o['at_risk'],
                'drafts'        => $o['drafts'],
                'pipeline'      => collect($o['pipeline'])->map(fn ($n, $s) => ['status' => $s, 'label' => OrderStatus::label($s), 'count' => $n])->values()->all(),
            ];
            $money = $u->can('View payment');
            // The dashboard list is a narrow select; load the full orders for balances.
            $full = Order::with('customer:id,public_id,first_name,last_name')->whereKey(collect($o['due'])->pluck('id'))->get()->keyBy('id');
            $out['due_soon'] = collect($o['due'])->map(fn (Order $x) => $full->get($x->id) ?? $x)->map(fn (Order $x) => [
                'id' => $x->public_id, 'number' => $x->number, 'title' => $x->title, 'status' => $x->status, 'status_label' => $x->status_label,
                'promised_date' => $x->promised_date?->toDateString(), 'priority' => $x->priority, 'risk' => $x->risk_level,
                'customer' => ['id' => $x->customer?->public_id, 'name' => $x->customer?->name],
            ] + ($money ? ['balance' => $x->balance()] : []))->values()->all();
        }

        if (isset($f['production'])) {
            $today = $now->toDateString();
            $mine = ProductionTask::where('assigned_to', $u->id)
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'draft']));
            $out['my_work'] = [
                'open'       => (clone $mine)->open()->count(),
                'overdue'    => (clone $mine)->open()->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count(),
                'due_today'  => (clone $mine)->open()->whereDate('due_date', $today)->count(),
                'done_today' => (clone $mine)->where('status', 'done')
                    ->whereBetween('completed_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])->count(),
            ];
            $out['workload'] = collect($f['production']['people'] ?? [])
                ->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'open' => $p['open'], 'overdue' => $p['late']])->values()->all();

            if ($u->can('Manage fitting') || $u->can('View production')) {
                $out['fittings_today'] = Fitting::with('customer:id,first_name,last_name', 'order:id,public_id,number', 'line:id,description')
                    ->whereIn('status', ['scheduled', 'attended', 'no_show'])
                    ->whereBetween('scheduled_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])
                    ->orderBy('scheduled_at')->limit(20)->get()
                    ->map(fn ($x) => $this->fittingData($x, $tz))->all();
            }
        }

        if (isset($f['stock'])) {
            $out['low_stock'] = collect($f['stock']['items'])->map(fn ($l) => [
                'item_id' => $l['item']->public_id, 'item' => $l['item']->name, 'unit' => $l['item']->unit,
                'branch' => $l['branch'], 'available' => $l['available'], 'reorder_level' => (float) $l['item']->reorder_level,
            ])->values()->all();
        }

        if (isset($f['follow_ups'])) {
            $tasks = FollowUpTask::open()->where('assigned_to', $u->id)
                ->orderByRaw('due_at is null')->orderBy('due_at')->limit(6)->get();
            $customers = Customer::whereIn('id', $tasks->pluck('customer_id')->filter())->get()->keyBy('id');
            $out['follow_ups'] = $tasks->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title,
                'due_at' => $t->due_at ? Carbon::parse($t->due_at)->toIso8601String() : null,
                'overdue' => $t->due_at && Carbon::parse($t->due_at)->isPast(),
                'customer' => ($c = $customers->get($t->customer_id)) ? ['id' => $c->public_id, 'name' => $c->full_name] : null,
            ])->values()->all();
        }

        return response()->json(['success' => true, 'data' => $out]);
    }

    /* --------------------------------- staff --------------------------------- */

    public function staff(): JsonResponse
    {
        $people = TenantMembership::active()->with('user')->get()
            ->filter(fn ($m) => $m->user && ! $m->user->is_disabled)
            ->map(fn ($m) => [
                'id' => $m->user->id, 'name' => $m->user->name, 'job_title' => $m->job_title,
                'is_owner' => (bool) $m->is_owner, 'branch_id' => $m->branch_id,
                'roles' => $m->user->getRoleNames()->values(),
            ])->sortBy('name')->values();

        return response()->json(['success' => true, 'data' => $people]);
    }

    /* -------------------------------- fittings ------------------------------- */

    public function fittings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'   => 'nullable|date',
            'to'     => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|string',
        ]);
        $tz = CurrentTenant::get()->timezone ?: config('app.timezone');
        $from = Carbon::parse($data['from'] ?? now($tz)->toDateString(), $tz)->startOfDay();
        $to = Carbon::parse($data['to'] ?? $from->copy()->addDays(6)->toDateString(), $tz)->endOfDay();
        if ($from->diffInDays($to) > 62) {
            $to = $from->copy()->addDays(62)->endOfDay();
        }

        $rows = Fitting::with('customer:id,public_id,first_name,last_name', 'order:id,public_id,number', 'line:id,description', 'staff:id,name')
            ->whereBetween('scheduled_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->when(! empty($data['status']), fn ($q) => $q->whereIn('status', explode(',', $data['status'])),
                fn ($q) => $q->whereNotIn('status', ['rescheduled', 'cancelled']))
            ->orderBy('scheduled_at')->limit(500)->get();

        return response()->json(['success' => true, 'data' => $rows->map(fn ($x) => $this->fittingData($x, $tz))->values()]);
    }

    protected function fittingData(Fitting $f, string $tz): array
    {
        return [
            'id' => $f->public_id, 'number' => $f->number, 'status' => $f->status, 'status_label' => $f->status_label,
            'outcome' => $f->outcome, 'scheduled_at' => $f->scheduled_at->copy()->timezone($tz)->toIso8601String(),
            'duration_minutes' => $f->duration_minutes,
            'customer' => $f->customer?->name, 'customer_id' => $f->customer?->public_id,
            'order' => $f->order?->number, 'order_id' => $f->order?->public_id,
            'line' => $f->order_line_id, 'garment' => $f->line?->description,
            'staff' => $f->relationLoaded('staff') ? $f->staff?->name : null,
        ];
    }

    /* ------------------------------ notifications ---------------------------- */

    public function notifications(Request $request): JsonResponse
    {
        $page = $this->scoped($request)->latest()->paginate(min(50, (int) $request->input('per_page', 25)));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($n) => [
                'id'         => $n->id,
                'title'      => $n->data['title'] ?? 'Notification',
                'body'       => $n->data['body'] ?? null,
                'url'        => $n->data['url'] ?? null,
                'target'     => $this->target($n->data['url'] ?? null),
                'read_at'    => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'meta'    => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'unread' => $this->scoped($request)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function readNotification(Request $request, string $id): JsonResponse
    {
        $n = $this->scoped($request)->whereKey($id)->firstOrFail();
        $n->markAsRead();

        return response()->json(['success' => true]);
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $this->scoped($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    protected function scoped(Request $request)
    {
        $tenantId = CurrentTenant::id();

        return $request->user()->notifications()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    /** Turns a notification's web link into something the app can open. */
    protected function target(?string $url): ?array
    {
        if (! $url) {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $map = [
            '#/orders/([A-Za-z0-9-]+)$#'           => 'order',
            '#/customers/([A-Za-z0-9-]+)$#'        => 'customer',
            '#/production/tasks/([A-Za-z0-9-]+)$#' => 'task',
            '#/inventory/([A-Za-z0-9-]+)$#'        => 'item',
        ];
        foreach ($map as $re => $type) {
            if (preg_match($re, $path, $m)) {
                return ['type' => $type, 'id' => $m[1]];
            }
        }

        return null;
    }
}
