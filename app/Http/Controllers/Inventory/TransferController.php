<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Services\Inventory\StockDocumentService;
use App\Support\Inventory\ItemPicker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransferController extends Controller
{
    public function __construct(protected StockDocumentService $docs)
    {
        $this->middleware('permission:View inventory', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage inventory', ['only' => ['create', 'store']]);
    }

    public function index(): View
    {
        return view('inventory.transfers.index', [
            'pagetitle' => 'Stock transfers',
            'transfers' => StockTransfer::with('fromBranch:id,name', 'toBranch:id,name', 'creator:id,name')->withCount('lines')
                ->latest('transferred_at')->latest('id')->paginate(30),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function create(): View
    {
        return view('inventory.transfers.form', [
            'pagetitle'   => 'Transfer stock',
            'branches'    => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'pickerItems' => ItemPicker::data(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'from_branch_id'   => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'to_branch_id'     => ['required', 'different:from_branch_id', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'notes'            => 'nullable|string|max:2000',
            'lines'            => 'required|array',
            'lines.*.item_id'  => 'nullable|integer',
            'lines.*.lot_id'   => 'nullable|integer',
            'lines.*.quantity' => 'nullable|numeric|min:0',
        ], ['to_branch_id.different' => 'Choose a different destination.']);
        $t = $this->docs->transfer((int) $data['from_branch_id'], (int) $data['to_branch_id'], $data['lines'], $data['notes'] ?? null);

        return redirect()->route('stock-transfers.show', $t)->with('success', 'Stock moved to ' . $t->toBranch->name . '.');
    }

    public function show(StockTransfer $stockTransfer): View
    {
        $stockTransfer->load('fromBranch', 'toBranch', 'lines.item', 'lines.lot', 'creator');

        return view('inventory.transfers.show', ['pagetitle' => $stockTransfer->number, 't' => $stockTransfer, 'tz' => CurrentTenant::get()->timezone]);
    }
}
