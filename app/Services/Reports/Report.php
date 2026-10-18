<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Tenancy\CurrentTenant;

/**
 * One report. Subclasses say who may see it (permissions, any of), which plan
 * features it needs, which filters apply, and build a ReportResult.
 */
abstract class Report
{
    /** URL key, e.g. "sales". */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    abstract public function run(ReportFilters $f, User $user): ReportResult;

    /** Money / Sales & orders / Stock / Workroom / Customers / Projects */
    public function group(): string
    {
        return 'Money';
    }

    public function icon(): string
    {
        return 'ri-bar-chart-box-line';
    }

    /** The user needs "View reports" plus any one of these (empty = nothing more). */
    public function permissions(): array
    {
        return [];
    }

    /** Plan features needed; reports_full marks the Pro-and-up reports. */
    public function features(): array
    {
        return [];
    }

    /** range, branch, staff, category, status */
    public function filters(): array
    {
        return ['range', 'branch'];
    }

    public function defaultPeriod(): string
    {
        return 'this_month';
    }

    /** Label for the "as of" reports that ignore the range. */
    public function asOf(): bool
    {
        return ! in_array('range', $this->filters(), true);
    }

    public function staffOptions(): array
    {
        return User::inCurrentTenant()->orderBy('name')->pluck('name', 'users.id')->all();
    }

    public function categoryOptions(): array
    {
        return [];
    }

    public function statusOptions(): array
    {
        return [];
    }

    public function branchOptions(): array
    {
        return Branch::orderBy('name')->pluck('name', 'id')->all();
    }

    public function canView(User $user): bool
    {
        if (! $user->can('View reports')) {
            return false;
        }
        $perms = $this->permissions();

        return ! $perms || collect($perms)->contains(fn ($p) => $user->can($p));
    }

    /** First missing plan feature, or null when the house's plan includes this report. */
    public function lockedBy(): ?string
    {
        $tenant = CurrentTenant::get();
        $ent = app(Entitlements::class);
        foreach ($this->features() as $feature) {
            if ($tenant && ! $ent->has($tenant, $feature)) {
                return $feature;
            }
        }

        return null;
    }

    public function isFull(): bool
    {
        return in_array('reports_full', $this->features(), true);
    }
}
