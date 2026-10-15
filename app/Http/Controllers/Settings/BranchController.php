<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Branches, production rooms, warehouses and POS points (SRS FR-TEN-004). */
class BranchController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View branch|Manage branch', ['only' => ['index']]);
        $this->middleware('permission:Manage branch', ['except' => ['index']]);
    }

    public function index(): View
    {
        $branches = Branch::withCount(['memberships' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('is_main')->orderBy('name')->get();

        return view('settings.branches', [
            'pagetitle' => 'Branches & Locations',
            'branches'  => $branches,
            'types'     => Branch::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branch = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $branch = Branch::create($data + ['created_by' => auth()->id()]);
            $this->ensureSingleMain($branch);

            return $branch;
        });

        AuditLogger::log('branch.created', $branch, after: $branch->only(['name', 'code', 'type']));

        return redirect()->route('settings.branches.index')->with('success', "{$branch->name} added.");
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $before = $branch->only(['name', 'code', 'type', 'is_main', 'is_active', 'address', 'phone']);

        DB::transaction(function () use ($request, $branch) {
            $data = $this->validated($request, $branch);

            if ($branch->is_main && empty($data['is_main'])) {
                $data['is_main'] = true;   // there must always be a main branch; choose another one as main instead
            }
            if ($branch->is_main && empty($data['is_active'])) {
                $data['is_active'] = true;
            }

            $branch->update($data + ['updated_by' => auth()->id()]);
            $this->ensureSingleMain($branch);
        });

        [$b, $a] = AuditLogger::diff($before, $branch->only(array_keys($before)));
        if ($a) {
            AuditLogger::log('branch.updated', $branch, $b, $a);
        }

        return redirect()->route('settings.branches.index')->with('success', "{$branch->name} updated.");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->is_main) {
            return back()->with('error', 'The main branch cannot be removed. Make another branch the main one first.');
        }

        AuditLogger::log('branch.deleted', $branch, before: $branch->only(['name', 'code']));
        $branch->memberships()->update(['branch_id' => null]);
        $branch->delete();

        return redirect()->route('settings.branches.index')->with('success', "{$branch->name} removed.");
    }

    protected function validated(Request $request, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'code'      => ['required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('branches', 'code')->where('tenant_id', CurrentTenant::id())->whereNull('deleted_at')->ignore($branch?->id)],
            'type'      => ['required', Rule::in(array_keys(Branch::TYPES))],
            'phone'     => 'nullable|string|max:30',
            'email'     => 'nullable|email|max:255',
            'address'   => 'nullable|string|max:255',
            'city'      => 'nullable|string|max:80',
            'state'     => 'nullable|string|max:80',
            'is_main'   => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['is_main'] = $request->boolean('is_main');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    protected function ensureSingleMain(Branch $branch): void
    {
        if ($branch->is_main) {
            Branch::where('id', '!=', $branch->id)->where('is_main', true)->update(['is_main' => false]);
        }
    }
}
