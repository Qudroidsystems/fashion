<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Services\Pos\PosService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Cashier shifts (SRS FR-POS-003, §6.3 steps 17 & 22). */
class ShiftController extends Controller
{
    public function __construct(protected PosService $pos)
    {
        $this->middleware('permission:Use POS', ['only' => ['index', 'show', 'movement']]);
        $this->middleware('permission:Open cash shift', ['only' => ['open']]);
        $this->middleware('permission:Close cash shift', ['only' => ['close']]);
    }

    public function index(Request $request): View
    {
        $mine = ! $request->user()->can('View financial reports') && ! $request->user()->can('Void sale');

        return view('pos.shifts', [
            'pagetitle' => 'Cash shifts',
            'shifts'    => CashShift::with('cashier:id,name', 'branch:id,name')->withCount(['sales' => fn ($q) => $q->where('status', 'completed')])
                ->withSum(['sales' => fn ($q) => $q->where('status', 'completed')], 'total')
                ->when($mine, fn ($q) => $q->where('user_id', $request->user()->id))
                ->latest('opened_at')->paginate(30),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id'     => ['required', Rule::exists('branches', 'id')->where('tenant_id', CurrentTenant::id())->whereNull('deleted_at')],
            'opening_float' => 'required|numeric|min:0|max:100000000',
            'notes'         => 'nullable|string|max:500',
        ]);
        $this->pos->openShift($request->user(), (int) $data['branch_id'], (float) $data['opening_float'], $data['notes'] ?? null);

        return redirect()->route('pos.terminal')->with('success', 'Shift opened. Happy selling!');
    }

    public function show(Request $request, CashShift $shift): View
    {
        abort_unless($shift->user_id === $request->user()->id || $request->user()->can('Void sale') || $request->user()->can('View financial reports'), 403);
        $shift->load('cashier', 'branch', 'closer', 'movements.user');

        return view('pos.shift', [
            'pagetitle' => $shift->number,
            'shift'     => $shift,
            'summary'   => $this->pos->summary($shift),
            'sales'     => $shift->sales()->with('customer:id,first_name,last_name')->latest('id')->get(),
            'returns'   => $shift->returns()->with('sale:id,public_id,number')->get(),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function movement(Request $request, CashShift $shift): RedirectResponse
    {
        abort_unless($shift->user_id === $request->user()->id, 403, 'Only the cashier on this shift can move its cash.');
        $data = $request->validate(['type' => 'required|in:pay_in,pay_out', 'amount' => 'required|numeric|min:1', 'reason' => 'required|string|max:255']);
        $this->pos->cashMovement($shift, $data['type'], (float) $data['amount'], $data['reason']);

        return back()->with('success', $data['type'] === 'pay_in' ? 'Cash added to the drawer.' : 'Cash taken out of the drawer.');
    }

    public function close(Request $request, CashShift $shift): RedirectResponse
    {
        abort_unless($shift->user_id === $request->user()->id || $request->user()->can('Void sale'), 403);
        $data = $request->validate(['counted_cash' => 'required|numeric|min:0', 'variance_reason' => 'nullable|string|max:255']);
        $shift = $this->pos->closeShift($shift, (float) $data['counted_cash'], $data['variance_reason'] ?? null, $request->user());

        return redirect()->route('pos.shifts.show', $shift)->with('success', $shift->variance == 0 ? 'Shift closed — the drawer balances.' : 'Shift closed with a difference of ' . \App\Support\Money::format($shift->variance) . '.');
    }
}
