<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Reports\Definitions;

/** Every report, in the order they are listed. */
class ReportRegistry
{
    public const REPORTS = [
        Definitions\SalesReport::class,
        Definitions\ProfitReport::class,
        Definitions\ReceivablesReport::class,
        Definitions\ExpensesReport::class,
        Definitions\OrdersReport::class,
        Definitions\ItemsReport::class,
        Definitions\StockReport::class,
        Definitions\ProductionReport::class,
        Definitions\StaffReport::class,
        Definitions\CustomersReport::class,
        Definitions\ProjectsReport::class,
    ];

    /** Names used by the SRS API list (§8.2). */
    public const ALIASES = [
        'stock-valuation' => 'stock', 'order-throughput' => 'orders', 'project-profitability' => 'projects', 'profit-and-loss' => 'profit',
    ];

    /** @return Report[] keyed by report key */
    public function all(): array
    {
        $out = [];
        foreach (self::REPORTS as $class) {
            $r = app($class);
            $out[$r->key()] = $r;
        }

        return $out;
    }

    public function find(string $key): ?Report
    {
        return $this->all()[self::ALIASES[$key] ?? $key] ?? null;
    }

    /** @return Report[] */
    public function visibleTo(User $user): array
    {
        return array_filter($this->all(), fn (Report $r) => $r->canView($user));
    }
}
