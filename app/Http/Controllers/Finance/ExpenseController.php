<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * House running costs (FR-REP-005 "expenses"). Correctable on the day they are
 * recorded; after that they can only be voided with a reason, so last month's
 * profit never changes without a trace.
 */
class ExpenseController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()->can('Manage expense') || $request->user()->can('View financial reports'), 403);

            return $next($request);
        })->only(['index', 'receipt']);
        $this->middleware('permission:Manage expense', ['only' => ['create', 'store', 'edit', 'update', 'void']]);
    }

    public function index(Request $request): View
    {
        $tz = CurrentTenant::get()->timezone;
        $from = $request->date('from') ?? now($tz)->startOfMonth();
        $to = $request->date('to') ?? now($tz);
        $base = Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString() . ' 23:59:59'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->when($request->filled('branch'), fn ($q) => $q->where('branch_id', (int) $request->input('branch')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$request->q}%")
                ->orWhere('paid_to', 'like', "%{$request->q}%")->orWhere('number', 'like', "%{$request->q}%")));

        return view('expenses.index', [
            'pagetitle'  => 'Expenses',
            'expenses'   => (clone $base)->with('branch:id,name', 'recorder:id,name')->orderByDesc('spent_on')->orderByDesc('id')->paginate(40)->withQueryString(),
            'total'      => (float) (clone $base)->counted()->sum('amount'),
            'byCategory' => (clone $base)->counted()->selectRaw('category, sum(amount) total, count(*) n')->groupBy('category')->orderByDesc('total')->get(),
            'categories' => $this->categories(),
            'branches'   => Branch::orderBy('name')->get(['id', 'name']),
            'from'       => $from,
            'to'         => $to,
            'tz'         => $tz,
        ]);
    }

    public function create(): View
    {
        return view('expenses.form', ['pagetitle' => 'Record expense', 'expense' => new Expense(['spent_on' => now(CurrentTenant::get()->timezone), 'method' => 'cash']),
            'categories' => $this->categories(), 'branches' => Branch::active()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $expense = Expense::create($data + [
            'number' => DocumentNumber::next('expense'), 'recorded_by' => $request->user()->id, 'status' => 'recorded',
            'receipt_path' => $this->storeReceipt($request),
        ]);
        AuditLogger::log('expense.recorded', $expense, after: ['amount' => $expense->amount, 'category' => $expense->category]);

        return redirect()->route($request->boolean('another') ? 'expenses.create' : 'expenses.index')->with('success', "Expense {$expense->number} recorded.");
    }

    public function edit(Expense $expense): View
    {
        abort_unless($expense->isEditable(), 403, 'Only today\'s expenses can be changed. Void it and record it again.');

        return view('expenses.form', ['pagetitle' => 'Change expense', 'expense' => $expense, 'categories' => $this->categories(),
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name'])]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless($expense->isEditable(), 403, 'Only today\'s expenses can be changed. Void it and record it again.');
        $before = $expense->only(['category', 'description', 'amount', 'spent_on', 'method', 'paid_to']);
        $data = $this->validated($request);
        if ($path = $this->storeReceipt($request)) {
            $expense->receipt_path && Storage::disk('local')->delete($expense->receipt_path);
            $data['receipt_path'] = $path;
        }
        $expense->update($data);
        AuditLogger::log('expense.changed', $expense, $before, $expense->only(array_keys($before)));

        return redirect()->route('expenses.index')->with('success', "Expense {$expense->number} updated.");
    }

    public function void(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        abort_if($expense->status === 'void', 422, 'Already voided.');
        $expense->update(['status' => 'void', 'void_reason' => $data['reason'], 'voided_at' => now(), 'voided_by' => $request->user()->id]);
        AuditLogger::log('expense.voided', $expense, after: ['amount' => $expense->amount], description: $data['reason']);

        return back()->with('success', "Expense {$expense->number} voided.");
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->response($expense->receipt_path, $expense->number . '.' . pathinfo($expense->receipt_path, PATHINFO_EXTENSION));
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'category'    => 'required|string|max:60',
            'description' => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0.01|max:999999999',
            'spent_on'    => 'required|date|before_or_equal:' . now(CurrentTenant::get()->timezone)->addDay()->toDateString(),
            'method'      => ['required', Rule::in(array_keys(Expense::METHODS))],
            'paid_to'     => 'nullable|string|max:120',
            'reference'   => 'nullable|string|max:120',
            'branch_id'   => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', CurrentTenant::id())],
            'receipt'     => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ], ['spent_on.before_or_equal' => 'Expenses can\'t be dated in the future.']);
    }

    protected function storeReceipt(Request $request): ?string
    {
        $file = $request->file('receipt');

        return $file?->storeAs('tenants/' . CurrentTenant::get()->public_id . '/expenses', Str::random(16) . '.' . $file->extension(), 'local');
    }

    /** Starting list plus whatever the house has typed before. */
    protected function categories(): array
    {
        return collect(Expense::CATEGORIES)->merge(Expense::query()->distinct()->pluck('category'))->unique()->values()->all();
    }
}
