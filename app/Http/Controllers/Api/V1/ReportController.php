<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS §8.2 Reports:
 *   GET /reports                         — the reports this user may open
 *   GET /reports/{key}?period=&from=&to=&branch=&staff=&category=&status=
 * Keys: sales, profit, receivables, expenses, orders, items, stock, production, staff, customers, projects
 * (aliases: stock-valuation, order-throughput, project-profitability, profit-and-loss).
 * Same permissions, plan and branch limits as the web screens.
 */
class ReportController extends Controller
{
    public function __construct(protected ReportRegistry $reports)
    {
        $this->middleware('permission:View reports');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn (Report $r) => [
            'key' => $r->key(), 'title' => $r->title(), 'description' => $r->description(), 'group' => $r->group(),
            'filters' => $r->filters(), 'default_period' => $r->defaultPeriod(), 'locked_by' => $r->lockedBy(),
        ], $this->reports->visibleTo($request->user())))]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $def = $this->reports->find($report);
        abort_unless($def, 404);
        abort_unless($def->canView($request->user()), 403, 'Your role can\'t see this report.');
        if ($feature = $def->lockedBy()) {
            return response()->json(['message' => 'This report is not included in your plan.', 'feature' => $feature], 402);
        }
        $f = ReportFilters::fromRequest($request, $def->defaultPeriod());

        return response()->json([
            'report' => ['key' => $def->key(), 'title' => $def->title()],
            'filters' => [
                'period' => $f->preset, 'from' => $f->fromDate(), 'to' => $f->toDate(), 'label' => $def->periodText($f), 'all_time' => $f->allTime,
                'branch_id' => $f->branchId, 'branch_locked' => $f->branchLocked, 'staff_id' => $f->staffId, 'category' => $f->category, 'status' => $f->status,
                'timezone' => $f->tz,
            ],
            'data' => $def->run($f, $request->user())->toArray(),
        ]);
    }
}
