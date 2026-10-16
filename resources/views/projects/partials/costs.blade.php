{{-- Budget against actual cost (FR-PRJ-007): stock issued at cost plus recorded expenses. --}}
@php use App\Support\Money; $c = $s['costs']; @endphp
<x-cb.card title="Budget & costs" icon="ri-scales-3-line" class="mt-3">
    <div class="d-flex justify-content-between small"><span class="text-muted">Budget</span><span>{{ $c['budget'] > 0 ? Money::format($c['budget']) : '—' }}</span></div>
    <div class="d-flex justify-content-between small"><span class="text-muted">Material issued (at cost)</span><span>{{ Money::format($c['materials']) }}</span></div>
    <div class="d-flex justify-content-between small"><span class="text-muted">Other expenses</span><span>{{ Money::format($c['expenses']) }}</span></div>
    <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-1"><span>Actual cost</span><span>{{ Money::format($c['actual']) }}</span></div>
    @if($c['remaining_budget'] !== null)<div class="small {{ $c['remaining_budget'] < 0 ? 'text-danger' : 'text-muted' }}">{{ $c['remaining_budget'] < 0 ? 'Over budget by ' . Money::format(abs($c['remaining_budget'])) : Money::format($c['remaining_budget']) . ' of the budget left' }}</div>@endif
    <div class="d-flex justify-content-between small mt-2"><span class="text-muted">Margin so far</span>
        <span class="{{ $c['margin'] < 0 ? 'text-danger' : 'text-success' }}">{{ Money::format($c['margin']) }}{{ $c['margin_percent'] !== null ? ' (' . $c['margin_percent'] . '%)' : '' }}</span></div>

    @if($project->budgetLines->isNotEmpty())
        <div class="small fw-semibold mt-3 mb-1">Budget lines</div>
        @foreach($project->budgetLines as $b)
            <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $categories[$b->category] ?? $b->category }}{{ $b->description ? ' · ' . $b->description : '' }}</span>
                <span class="d-flex gap-2">{{ Money::format($b->amount) }}@if($canManage && $open)<form method="POST" action="{{ route('projects.budget.destroy', [$project, $b]) }}">@csrf @method('DELETE')<button class="btn btn-link btn-sm text-muted p-0"><i class="ri-close-line"></i></button></form>@endif</span></div>
        @endforeach
    @endif
    @if($project->expenses->isNotEmpty())
        <div class="small fw-semibold mt-3 mb-1">Expenses</div>
        @foreach($project->expenses as $e)
            <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $e->description }}<span class="d-block text-muted">{{ $e->spent_on->format('d M') }} · {{ $categories[$e->category] ?? $e->category }}{{ $e->paid_to ? ' · ' . $e->paid_to : '' }}
                @if($e->receipt_path) · <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($e->receipt_path) }}" target="_blank">receipt</a>@endif</span></span>
                <span class="d-flex gap-2">{{ Money::format($e->amount) }}@if($canManage && $open)<form method="POST" action="{{ route('projects.expenses.destroy', [$project, $e]) }}" onsubmit="return confirm('Remove this expense?')">@csrf @method('DELETE')<button class="btn btn-link btn-sm text-muted p-0"><i class="ri-close-line"></i></button></form>@endif</span></div>
        @endforeach
    @endif
    @if($canManage && $open)
        <ul class="nav nav-pills gap-1 mt-3 small"><li class="nav-item"><button class="nav-link py-1 px-2 active" data-bs-toggle="tab" data-bs-target="#addExp" type="button">Expense</button></li>
            <li class="nav-item"><button class="nav-link py-1 px-2" data-bs-toggle="tab" data-bs-target="#addBud" type="button">Budget line</button></li></ul>
        <div class="tab-content mt-2">
            <form method="POST" action="{{ route('projects.expenses.store', $project) }}" enctype="multipart/form-data" class="tab-pane fade show active row g-1" id="addExp">@csrf
                <div class="col-7"><input name="description" class="form-control form-control-sm" required maxlength="160" placeholder="e.g. Beader, 3 days"></div>
                <div class="col-5"><input type="number" name="amount" min="0.01" step="0.01" class="form-control form-control-sm" required placeholder="{{ Money::symbol() }}"></div>
                <div class="col-6"><select name="category" class="form-select form-select-sm">@foreach($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-6"><input type="date" name="spent_on" class="form-control form-control-sm" required value="{{ now($tz)->toDateString() }}"></div>
                <div class="col-6"><input name="paid_to" class="form-control form-control-sm" maxlength="120" placeholder="Paid to"></div>
                <div class="col-6"><input type="file" name="receipt" class="form-control form-control-sm" accept="image/*,.pdf"></div>
                <div class="col-12 d-grid"><button class="btn btn-sm btn-light">Record expense</button></div>
                <div class="col-12 small text-muted">Fabric and trims taken from stock are counted automatically when issued for the project’s orders.</div>
            </form>
            <form method="POST" action="{{ route('projects.budget.store', $project) }}" class="tab-pane fade row g-1" id="addBud">@csrf
                <div class="col-6"><select name="category" class="form-select form-select-sm">@foreach($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-6"><input type="number" name="amount" min="0" step="0.01" class="form-control form-control-sm" required placeholder="{{ Money::symbol() }}"></div>
                <div class="col-12"><input name="description" class="form-control form-control-sm" maxlength="160" placeholder="Note (optional)"></div>
                <div class="col-12 d-grid"><button class="btn btn-sm btn-light">Add budget line</button></div>
            </form>
        </div>
    @endif
</x-cb.card>
