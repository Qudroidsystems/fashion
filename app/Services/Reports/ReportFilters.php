<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * What a report is about (SRS FR-REP-002): a date range in the house's time
 * zone, and optional branch, staff, category and status. Someone who may only
 * see their own branch's figures gets that branch whatever they ask for
 * (FR-REP-006: exports use the same filters, so the same limits).
 */
class ReportFilters
{
    public const PRESETS = [
        'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week',
        'this_month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter', 'this_year' => 'This year', 'last_year' => 'Last year', 'custom' => 'Pick dates',
    ];

    public string $tz;
    public string $preset;
    public CarbonImmutable $from;     // local, start of day
    public CarbonImmutable $to;       // local, end of day
    public ?int $branchId = null;
    public bool $branchLocked = false;
    public ?int $staffId = null;
    public ?string $category = null;
    public ?string $status = null;
    public string $group = 'day';

    public static function make(array $input, ?User $user = null, string $defaultPreset = 'this_month'): self
    {
        $f = new self();
        $f->tz = CurrentTenant::get()?->timezone ?: config('app.timezone');
        $now = CarbonImmutable::now($f->tz);
        $f->preset = array_key_exists($input['period'] ?? '', self::PRESETS) ? $input['period'] : $defaultPreset;

        [$from, $to] = match ($f->preset) {
            'today'        => [$now, $now],
            'yesterday'    => [$now->subDay(), $now->subDay()],
            'this_week'    => [$now->startOfWeek(), $now],
            'last_week'    => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'last_month'   => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_30'      => [$now->subDays(29), $now],
            'this_quarter' => [$now->startOfQuarter(), $now],
            'last_quarter' => [$now->subQuarterNoOverflow()->startOfQuarter(), $now->subQuarterNoOverflow()->endOfQuarter()],
            'this_year'    => [$now->startOfYear(), $now],
            'last_year'    => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            'custom'       => [self::date($input['from'] ?? null, $f->tz) ?? $now->startOfMonth(), self::date($input['to'] ?? null, $f->tz) ?? $now],
            default        => [$now->startOfMonth(), $now],
        };
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 3 * 366) {   // keep heavy reports bounded
            $from = $to->subYears(3);
        }
        $f->from = $from->startOfDay();
        $f->to = $to->endOfDay();
        $days = $f->from->diffInDays($f->to) + 1;
        $f->group = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');

        $f->branchId = ! empty($input['branch']) ? (int) $input['branch'] : null;
        $f->staffId = ! empty($input['staff']) ? (int) $input['staff'] : null;
        $f->category = isset($input['category']) && $input['category'] !== '' ? (string) $input['category'] : null;
        $f->status = isset($input['status']) && $input['status'] !== '' ? (string) $input['status'] : null;

        if ($user && ($home = self::homeBranch($user))) {
            $f->branchId = $home;
            $f->branchLocked = true;
        }

        return $f;
    }

    public static function fromRequest(Request $request, string $defaultPreset = 'this_month'): self
    {
        return self::make($request->only(['period', 'from', 'to', 'branch', 'staff', 'category', 'status']), $request->user(), $defaultPreset);
    }

    /** The branch a person is limited to, or null for every branch. */
    public static function homeBranch(User $user): ?int
    {
        if ($user->isOwnerOfCurrentTenant() || $user->can('View all branches')) {
            return null;
        }

        return CurrentTenant::membership()?->branch_id;
    }

    protected static function date(?string $value, string $tz): ?CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('Y-m-d', $value, $tz) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * Bounds for a DATE column. The upper bound covers the whole last day, so a
     * date stored with a time part (as SQLite does) is still inside the range.
     */
    public function dateRange(): array
    {
        return [$this->fromDate(), $this->toDate() . ' 23:59:59'];
    }

    public function label(): string
    {
        if ($this->fromDate() === $this->toDate()) {
            return $this->from->format('j M Y');
        }

        return $this->from->format($this->from->year === $this->to->year ? 'j M' : 'j M Y') . ' – ' . $this->to->format('j M Y');
    }

    /** Period key for a moment (UTC timestamp or local date string). */
    public function bucket($moment): string
    {
        $d = $moment instanceof \DateTimeInterface
            ? CarbonImmutable::instance($moment)->setTimezone($this->tz)
            : (strlen((string) $moment) <= 10 ? CarbonImmutable::parse($moment, $this->tz) : CarbonImmutable::parse($moment, 'UTC')->setTimezone($this->tz));

        return match ($this->group) {
            'day'   => $d->toDateString(),
            'week'  => $d->startOfWeek()->toDateString(),
            default => $d->format('Y-m'),
        };
    }

    /** Every period in the range, in order: key => label. */
    public function buckets(): array
    {
        $out = [];
        $cursor = match ($this->group) {
            'day'   => $this->from,
            'week'  => $this->from->startOfWeek(),
            default => $this->from->startOfMonth(),
        };
        while ($cursor->lte($this->to) && count($out) < 400) {
            $key = $this->bucket($cursor->toDateString());
            $out[$key] = match ($this->group) {
                'day'   => $cursor->format('j M'),
                'week'  => 'Wk of ' . $cursor->format('j M'),
                default => $cursor->format('M Y'),
            };
            $cursor = match ($this->group) {
                'day'   => $cursor->addDay(),
                'week'  => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow()->startOfMonth(),
            };
        }

        return $out;
    }

    /** Query string to reproduce this view (used by exports and links). */
    public function query(): array
    {
        return array_filter([
            'period' => $this->preset,
            'from' => $this->preset === 'custom' ? $this->fromDate() : null,
            'to' => $this->preset === 'custom' ? $this->toDate() : null,
            'branch' => $this->branchLocked ? null : $this->branchId,
            'staff' => $this->staffId, 'category' => $this->category, 'status' => $this->status,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
