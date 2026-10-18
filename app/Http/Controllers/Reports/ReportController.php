<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Entitlements;
use App\Services\Reports\Report;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports (SRS §5.13). Every report needs "View reports" plus the report's own
 * permission; exports also need "Export reports". Reports outside the house's
 * plan show what they would contain and how to unlock them. CSV and Excel need
 * the plan's export feature; a PDF printout is available on every plan.
 */
class ReportController extends Controller
{
    public function __construct(protected ReportRegistry $reports, protected ReportExporter $exporter)
    {
        $this->middleware('permission:View reports');
        $this->middleware('permission:Export reports', ['only' => ['export']]);
    }

    public function index(Request $request): View
    {
        $visible = $this->reports->visibleTo($request->user());
        abort_if(! $visible, 403, 'You can\'t see any report yet. Ask the owner to give your role the report permissions.');

        return view('reports.index', [
            'pagetitle' => 'Reports',
            'groups'    => collect($visible)->groupBy(fn (Report $r) => $r->group(), true),
            'locks'     => collect($visible)->map(fn (Report $r) => $r->lockedBy())->all(),
        ]);
    }

    public function show(Request $request, string $report): View
    {
        $def = $this->find($request, $report);
        $f = ReportFilters::fromRequest($request, $def->defaultPeriod());
        $lock = $def->lockedBy();

        return view('reports.show', [
            'pagetitle'  => $def->title(),
            'report'     => $def,
            'f'          => $f,
            'result'     => $lock ? null : $def->run($f, $request->user()),
            'lock'       => $lock,
            'lockText'   => $lock ? app(Entitlements::class)->upgradeMessage(CurrentTenant::require(), $lock) : null,
            'options'    => $this->options($def, $f),
            'labels'     => $this->filterLabels($def, $f),
            'canExport'  => $request->user()->can('Export reports') && ! $lock,
            'canSheet'   => $this->canSheet(),
            'others'     => collect($this->reports->visibleTo($request->user()))->reject(fn ($r) => $r->key() === $def->key()),
        ]);
    }

    public function export(Request $request, string $report, string $format): Response
    {
        $def = $this->find($request, $report);
        abort_if($def->lockedBy(), 403, 'This report is not included in your plan.');
        if (in_array($format, ['csv', 'xlsx'], true) && ! $this->canSheet()) {
            return back()->with('warning', app(Entitlements::class)->upgradeMessage(CurrentTenant::require(), 'export') . ' You can still download a PDF.');
        }
        $f = ReportFilters::fromRequest($request, $def->defaultPeriod());
        $result = $def->run($f, $request->user());
        $tenant = CurrentTenant::require();
        $labels = $this->filterLabels($def, $f);

        AuditLogger::log('report.exported', null, after: ['report' => $def->key(), 'format' => $format, 'period' => $f->label()] + $labels,
            description: $def->title() . ' exported as ' . strtoupper($format));

        return match ($format) {
            'csv'  => $this->exporter->csv($def, $f, $result, $tenant, $labels),
            'xlsx' => $this->exporter->xlsx($def, $f, $result, $tenant, $labels),
            default => $this->exporter->pdf($def, $f, $result, $tenant, $labels),
        };
    }

    protected function find(Request $request, string $key): Report
    {
        $def = $this->reports->find($key);
        abort_unless($def, 404);
        abort_unless($def->canView($request->user()), 403, 'Your role can\'t see this report.');

        return $def;
    }

    protected function canSheet(): bool
    {
        $tenant = CurrentTenant::get();

        return ! $tenant || app(Entitlements::class)->has($tenant, 'export');
    }

    /** Choices for the filter bar, only for the filters this report uses. */
    protected function options(Report $def, ReportFilters $f): array
    {
        $uses = $def->filters();

        return [
            'presets'  => in_array('range', $uses, true) ? ReportFilters::PRESETS : [],
            'branches' => in_array('branch', $uses, true) && ! $f->branchLocked && Branch::count() > 1 ? $def->branchOptions() : [],
            'staff'    => in_array('staff', $uses, true) ? $def->staffOptions() : [],
            'category' => in_array('category', $uses, true) ? $def->categoryOptions() : [],
            'status'   => in_array('status', $uses, true) ? $def->statusOptions() : [],
        ];
    }

    /** The filters in words, for export headings and the audit log. */
    protected function filterLabels(Report $def, ReportFilters $f): array
    {
        $out = [];
        if ($f->branchId) {
            $out['Branch'] = Branch::whereKey($f->branchId)->value('name') ?? '—';
        }
        if ($f->staffId) {
            $out['Staff'] = User::whereKey($f->staffId)->value('name') ?? '—';
        }
        if ($f->category !== null) {
            $out['Category'] = $def->categoryOptions()[$f->category] ?? $f->category;
        }
        if ($f->status !== null) {
            $out['Stage'] = $def->statusOptions()[$f->status] ?? $f->status;
        }

        return $out;
    }
}
