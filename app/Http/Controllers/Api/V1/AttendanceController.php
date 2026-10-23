<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR attendance for the staff app.
 *   POST /attendance/scan      {code, latitude?, longitude?} — anyone active in the house
 *   GET  /attendance/me?days=  — my recent days and whether I'm in now
 *   GET  /attendance?date=&branch_id= — who's in (View attendance)
 *   POST /attendance           {user_id, direction, at, note} — add a forgotten scan (Manage attendance)
 *   GET  /attendance/code?branch_id= — the current rotating code, for a phone/tablet used as the entrance screen (Manage attendance)
 */
class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendance)
    {
        $this->middleware('permission:View attendance', ['only' => ['index']]);
        $this->middleware('permission:Manage attendance', ['only' => ['store', 'code']]);
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'      => ['required', 'string', 'max:300'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $r = $this->attendance->scan($request->user(), $data['code'], $data + [
            'ip' => $request->ip(), 'device' => $request->userAgent(),
        ]);
        $e = $r['event'];
        $tz = $this->attendance->tz();
        $today = $this->today($request->user());
        $time = $e->occurred_at->copy()->timezone($tz)->format('g:i A');
        $message = $r['repeated']
            ? 'Already recorded at ' . $time . '.'
            : ($e->direction === 'in' ? 'Welcome in. Clocked in at ' . $time . '.' : 'Goodbye. Clocked out at ' . $time . '.');

        return response()->json(['success' => true, 'data' => [
            'direction' => $e->direction, 'at' => $e->occurred_at->copy()->timezone($tz)->toIso8601String(),
            'branch' => $e->branch?->name, 'repeated' => $r['repeated'], 'message' => $message,
            'today' => $today,
        ]], $r['repeated'] ? 200 : 201);
    }

    public function me(Request $request): JsonResponse
    {
        $days = min(62, max(1, (int) $request->query('days', 14)));
        $tz = $this->attendance->tz();
        $to = CarbonImmutable::now($tz);
        $history = $this->attendance->history($request->user(), $to->subDays($days - 1), $to);

        return response()->json(['success' => true, 'data' => [
            'today' => $this->today($request->user()),
            'days' => $history->map(fn ($d) => $this->dayOut($d))->values(),
            'settings' => array_intersect_key($this->attendance->settings(), array_flip(['start_time', 'grace_minutes'])),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);
        $tz = $this->attendance->tz();
        $date = $request->filled('date') ? CarbonImmutable::parse($request->query('date'), $tz) : CarbonImmutable::now($tz);
        $branchId = $this->branchFor($request);
        $rows = $this->attendance->day($date, $branchId);

        return response()->json(['success' => true, 'data' => [
            'date' => $date->toDateString(),
            'counts' => [
                'in' => $rows->where('status', 'in')->count(), 'out' => $rows->where('status', 'out')->count(),
                'absent' => $rows->where('status', 'absent')->count(), 'late' => $rows->whereNotNull('late_minutes')->count(),
            ],
            'staff' => $rows->map(fn ($r) => $this->dayOut($r))->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'   => ['required', 'integer'],
            'direction' => ['required', 'in:in,out'],
            'at'        => ['required', 'date', 'before_or_equal:now'],
            'note'      => ['required', 'string', 'max:255'],
        ]);
        $m = TenantMembership::where('user_id', $data['user_id'])->first();
        abort_unless($m, 422, 'That person is not staff in this house.');
        $tz = $this->attendance->tz();
        $event = $this->attendance->addManual(User::findOrFail($data['user_id']), $data['direction'],
            CarbonImmutable::parse($data['at'], $tz), $data['note'], $request->user(), $m->branch_id);

        return response()->json(['success' => true, 'data' => ['id' => $event->public_id]], 201);
    }

    public function code(Request $request): JsonResponse
    {
        $branch = Branch::active()->find($this->branchFor($request)) ?? Branch::active()->orderBy('id')->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->attendance->rotatingCode($branch) + ['branch' => $branch->name]]);
    }

    /* ------------------------------------------------------------------ */

    protected function today(User $user): array
    {
        $tz = $this->attendance->tz();
        $now = CarbonImmutable::now($tz);
        $events = AttendanceEvent::with('branch:id,name')->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$now->startOfDay()->utc(), $now->endOfDay()->utc()])->orderBy('occurred_at')->orderBy('id')->get();
        $m = TenantMembership::active()->where('user_id', $user->id)->first();

        return $this->dayOut($this->attendance->summarise($user, $m, $events, $now));
    }

    protected function dayOut(array $d): array
    {
        $iso = fn ($t) => $t?->toIso8601String();

        return [
            'date' => $d['date'] ?? null, 'user_id' => $d['user_id'], 'name' => $d['name'], 'avatar_url' => $d['avatar_url'],
            'job_title' => $d['job_title'], 'status' => $d['status'], 'first_in' => $iso($d['first_in']), 'last_out' => $iso($d['last_out']),
            'worked_minutes' => $d['worked_minutes'], 'late_minutes' => $d['late_minutes'], 'branch' => $d['branch'],
            'events' => array_map(fn ($e) => ['at' => $iso($e['at'])] + $e, $d['events']),
        ];
    }

    /** Branch staff only see their branch, unless they can see all branches. */
    protected function branchFor(Request $request): ?int
    {
        $m = CurrentTenant::membership();
        if ($request->user()->can('View all branches') || $m?->is_owner || ! $m?->branch_id) {
            return $request->integer('branch_id') ?: null;
        }

        return $m->branch_id;
    }
}
