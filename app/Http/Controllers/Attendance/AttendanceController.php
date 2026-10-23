<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Attendance on the website:
 *  - the day board (who is in, who left, who hasn't come, late arrivals), with manual corrections
 *  - the entrance screen: a full-screen QR that changes every 30 seconds (open it on a tablet or TV at the door)
 *  - a printable QR, for houses that switch printed codes on
 *  - settings (start time, grace, which codes work)
 */
class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendance)
    {
        $this->middleware('permission:View attendance', ['only' => ['index']]);
        $this->middleware('permission:Manage attendance', ['except' => ['index']]);
    }

    public function index(Request $request): View
    {
        $request->validate(['date' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);
        $tz = $this->attendance->tz();
        $date = $request->filled('date') ? CarbonImmutable::parse($request->query('date'), $tz) : CarbonImmutable::now($tz);
        $branchId = $this->branchFor($request);
        $rows = $this->attendance->day($date, $branchId);

        return view('attendance.index', [
            'date' => $date, 'rows' => $rows, 'tz' => $tz, 'branchId' => $branchId,
            'branches' => $this->canPickBranch($request) ? Branch::active()->orderBy('name')->get(['id', 'name']) : collect(),
            'settings' => $this->attendance->settings(),
            'staff' => $rows->map(fn ($r) => ['id' => $r['user_id'], 'name' => $r['name']]),
            'isToday' => $date->isSameDay(CarbonImmutable::now($tz)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id'   => ['required', 'integer'],
            'direction' => ['required', 'in:in,out'],
            'date'      => ['required', 'date', 'before_or_equal:today'],
            'time'      => ['required', 'date_format:H:i'],
            'note'      => ['required', 'string', 'max:255'],
        ]);
        $m = TenantMembership::where('user_id', $data['user_id'])->firstOrFail();
        $tz = $this->attendance->tz();
        $at = CarbonImmutable::parse($data['date'] . ' ' . $data['time'], $tz);
        if ($at->isFuture()) {
            return back()->withErrors(['time' => 'That time hasn’t happened yet.'])->withInput();
        }
        $this->attendance->addManual(User::findOrFail($data['user_id']), $data['direction'], $at, $data['note'], $request->user(), $m->branch_id);

        return redirect()->route('attendance.index', ['date' => $at->toDateString()])->with('success', 'Added. It shows as “added by a manager”.');
    }

    public function kiosk(Request $request): View
    {
        $branch = $this->kioskBranch($request);

        return view('attendance.kiosk', [
            'branch' => $branch, 'tenant' => CurrentTenant::require(),
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
            'mode' => $this->attendance->settings()['mode'],
        ]);
    }

    /** The current code as an SVG, fetched by the entrance screen every few seconds. */
    public function kioskCode(Request $request): JsonResponse
    {
        $branch = $this->kioskBranch($request);
        if ($this->attendance->settings()['mode'] === 'static') {
            return response()->json(['off' => true]);
        }
        $c = $this->attendance->rotatingCode($branch);

        return response()->json([
            'svg' => (string) QrCode::format('svg')->size(520)->margin(1)->errorCorrection('M')->generate($c['code']),
            'expires_in' => $c['expires_in'],
            'time' => now($this->attendance->tz())->format('g:i A'),
        ])->header('Cache-Control', 'no-store');
    }

    public function printable(Request $request): View
    {
        $branch = $this->kioskBranch($request);

        return view('attendance.print', [
            'branch' => $branch, 'tenant' => CurrentTenant::require(), 'settings' => $this->attendance->settings(),
            'svg' => (string) QrCode::format('svg')->size(560)->margin(1)->errorCorrection('M')->generate($this->attendance->printedCode($branch)),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode'          => ['required', Rule::in(array_keys(AttendanceService::MODES))],
            'start_time'    => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);
        $data['grace_minutes'] = (int) $data['grace_minutes'];
        $this->attendance->saveSettings($data);

        return back()->with('success', 'Attendance settings saved.');
    }

    public function newPrintedCode(Request $request): RedirectResponse
    {
        $this->attendance->newPrintedCode();

        return back()->with('success', 'New printed code made. Old printed codes no longer work — print and put up the new one.');
    }

    /* ------------------------------------------------------------------ */

    protected function canPickBranch(Request $request): bool
    {
        $m = CurrentTenant::membership();

        return $request->user()->can('View all branches') || $m?->is_owner || ! $m?->branch_id;
    }

    protected function branchFor(Request $request): ?int
    {
        return $this->canPickBranch($request) ? ($request->integer('branch_id') ?: null) : CurrentTenant::membership()?->branch_id;
    }

    protected function kioskBranch(Request $request): Branch
    {
        $id = $this->canPickBranch($request) ? $request->integer('branch_id') : CurrentTenant::membership()?->branch_id;

        return ($id ? Branch::active()->find($id) : null) ?? Branch::active()->orderBy('id')->firstOrFail();
    }
}
