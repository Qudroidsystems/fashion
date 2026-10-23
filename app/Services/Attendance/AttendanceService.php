<?php

namespace App\Services\Attendance;

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QR attendance. The entrance shows a QR code; staff scan it with the app when they come in and
 * when they leave, and each scan records "in" or "out" (whichever is next for them that day).
 *
 * Codes are signed with the app key and the house, so they can't be made up:
 *   FHA1|<house public id>|<branch id>|<window>|<signature>
 * - Rotating (entrance screen, the default): <window> = the current 30-second slot; a code is
 *   accepted for about a minute, so a photo sent to someone at home is useless soon after.
 * - Printed (optional, house setting): <window> = "S<version>"; it works until a manager prints
 *   a new one, which cancels the old.
 */
class AttendanceService
{
    public const WINDOW_SECONDS = 30;

    /** Same person scanning twice this quickly is treated as one scan. */
    public const DOUBLE_SCAN_SECONDS = 60;

    public const MODES = [
        'rotating' => 'Entrance screen only (most secure)',
        'both'     => 'Entrance screen or printed code',
        'static'   => 'Printed code only',
    ];

    /* ------------------------------ settings ------------------------------ */

    public function settings(?Tenant $tenant = null): array
    {
        $t = $tenant ?? CurrentTenant::require();

        return [
            'mode'           => $t->setting('attendance.mode', 'rotating'),
            'start_time'     => $t->setting('attendance.start_time', '08:00'),
            'grace_minutes'  => (int) $t->setting('attendance.grace_minutes', 15),
            'static_version' => (int) $t->setting('attendance.static_version', 1),
        ];
    }

    public function saveSettings(array $data): void
    {
        $tenant = CurrentTenant::require();
        $settings = $tenant->settings ?? [];
        $settings['attendance'] = array_merge($settings['attendance'] ?? [], array_intersect_key($data, array_flip(['mode', 'start_time', 'grace_minutes'])));
        $tenant->update(['settings' => $settings]);
        AuditLogger::log('attendance.settings_changed', $tenant, after: $settings['attendance']);
    }

    /** Printing a new code makes every older printed code stop working. */
    public function newPrintedCode(): int
    {
        $tenant = CurrentTenant::require();
        $settings = $tenant->settings ?? [];
        $version = (int) data_get($settings, 'attendance.static_version', 1) + 1;
        data_set($settings, 'attendance.static_version', $version);
        $tenant->update(['settings' => $settings]);
        AuditLogger::log('attendance.printed_code_replaced', $tenant, after: ['version' => $version]);

        return $version;
    }

    /* -------------------------------- codes -------------------------------- */

    public function window(?int $at = null): int
    {
        return intdiv($at ?? now()->getTimestamp(), self::WINDOW_SECONDS);
    }

    /** The code the entrance screen shows right now, and when it changes. */
    public function rotatingCode(Branch $branch): array
    {
        $w = $this->window();

        return ['code' => $this->sign($branch, (string) $w), 'expires_in' => self::WINDOW_SECONDS - (now()->getTimestamp() % self::WINDOW_SECONDS)];
    }

    public function printedCode(Branch $branch): string
    {
        return $this->sign($branch, 'S' . $this->settings()['static_version']);
    }

    protected function sign(Branch $branch, string $window): string
    {
        $tenant = CurrentTenant::require();
        $payload = "FHA1|{$tenant->public_id}|{$branch->id}|{$window}";

        return $payload . '|' . $this->signature($payload, $tenant);
    }

    protected function signature(string $payload, Tenant $tenant): string
    {
        $raw = hash_hmac('sha256', $payload, config('app.key') . '|attendance|' . $tenant->id, true);

        return rtrim(strtr(base64_encode(substr($raw, 0, 15)), '+/', '-_'), '=');
    }

    /**
     * Checks a scanned code for the current house. Returns [Branch, method] or throws a
     * validation error that says plainly what is wrong.
     */
    public function verify(string $code): array
    {
        $tenant = CurrentTenant::require();
        $parts = explode('|', trim($code));
        if (count($parts) !== 5 || $parts[0] !== 'FHA1') {
            throw ValidationException::withMessages(['code' => 'That isn’t an attendance code. Scan the code at the entrance.']);
        }
        [, $house, $branchId, $window, $sig] = $parts;
        if (! hash_equals($tenant->public_id, $house)) {
            throw ValidationException::withMessages(['code' => 'This code belongs to another fashion house.']);
        }
        if (! hash_equals($this->signature(implode('|', array_slice($parts, 0, 4)), $tenant), $sig)) {
            throw ValidationException::withMessages(['code' => 'This code isn’t valid. Scan the code at the entrance again.']);
        }
        $branch = Branch::active()->find((int) $branchId);
        if (! $branch) {
            throw ValidationException::withMessages(['code' => 'This code is for a branch that is closed.']);
        }

        $settings = $this->settings($tenant);
        if (str_starts_with($window, 'S')) {
            if ($settings['mode'] === 'rotating') {
                throw ValidationException::withMessages(['code' => 'Printed codes are switched off. Scan the code on the entrance screen.']);
            }
            if ((int) substr($window, 1) !== $settings['static_version']) {
                throw ValidationException::withMessages(['code' => 'This printed code has been replaced. Scan the new one.']);
            }

            return [$branch, 'qr_static'];
        }

        if ($settings['mode'] === 'static') {
            throw ValidationException::withMessages(['code' => 'The entrance screen is switched off. Scan the printed code.']);
        }
        $age = $this->window() - (int) $window;
        if ($age < -1 || $age > 1) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Scan the code on the screen again.']);
        }

        return [$branch, 'qr_rotating'];
    }

    /* ------------------------------- scanning ------------------------------ */

    /** Records the scan as "in" or "out" (the next one for this person today). */
    public function scan(User $user, string $code, array $meta = []): array
    {
        $membership = TenantMembership::active()->where('user_id', $user->id)->first();
        if (! $membership) {
            throw ValidationException::withMessages(['code' => 'You are not active staff in this fashion house.']);
        }
        [$branch, $method] = $this->verify($code);

        return DB::transaction(function () use ($user, $branch, $method, $meta) {
            $last = AttendanceEvent::where('user_id', $user->id)->lockForUpdate()->latest('occurred_at')->latest('id')->first();
            if ($last && $last->occurred_at->diffInSeconds(now(), true) < self::DOUBLE_SCAN_SECONDS && $last->method !== 'manual') {
                return ['event' => $last, 'repeated' => true];
            }

            $direction = $this->nextDirection($user, $last);
            $event = AttendanceEvent::create([
                'user_id' => $user->id, 'branch_id' => $branch->id, 'direction' => $direction, 'occurred_at' => now(),
                'method' => $method, 'device' => isset($meta['device']) ? mb_substr((string) $meta['device'], 0, 120) : null,
                'ip' => $meta['ip'] ?? null, 'latitude' => $meta['latitude'] ?? null, 'longitude' => $meta['longitude'] ?? null,
            ]);

            return ['event' => $event, 'repeated' => false];
        });
    }

    /** "in" unless the person's last scan today was "in". A new day always starts with "in". */
    protected function nextDirection(User $user, ?AttendanceEvent $last): string
    {
        if (! $last) {
            return 'in';
        }
        $tz = $this->tz();
        $sameDay = $last->occurred_at->copy()->timezone($tz)->isSameDay(now($tz));

        return $sameDay && $last->direction === 'in' ? 'out' : 'in';
    }

    /** A manager adds a forgotten scan. */
    public function addManual(User $staff, string $direction, CarbonImmutable $at, string $note, User $by, ?int $branchId = null): AttendanceEvent
    {
        $event = AttendanceEvent::create([
            'user_id' => $staff->id, 'branch_id' => $branchId, 'direction' => $direction, 'occurred_at' => $at->utc(),
            'method' => 'manual', 'recorded_by' => $by->id, 'note' => $note,
        ]);
        AuditLogger::log('attendance.manual_added', $event, after: ['staff' => $staff->name, 'direction' => $direction, 'at' => $at->toIso8601String(), 'note' => $note]);

        return $event;
    }

    /* ------------------------------- reading ------------------------------- */

    public function tz(): string
    {
        return CurrentTenant::get()?->timezone ?: config('app.timezone');
    }

    /**
     * Everyone's day: status (in / out / not in), first in, last out, time worked, late.
     * $branchId limits it to scans at one branch plus that branch's staff.
     */
    public function day(CarbonImmutable $date, ?int $branchId = null): Collection
    {
        $tz = $this->tz();
        $from = $date->timezone($tz)->startOfDay();
        $to = $from->endOfDay();
        $events = AttendanceEvent::with('branch:id,name')->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->orderBy('occurred_at')->orderBy('id')->get()->groupBy('user_id');

        $members = TenantMembership::active()->with('user:id,name,email,avatar,is_disabled')
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->get()->filter(fn ($m) => $m->user && ! $m->user->is_disabled);

        return $members->map(fn ($m) => $this->summarise($m->user, $m, $events->get($m->user_id, collect()), $date))
            ->sortBy([['status_order', 'asc'], ['name', 'asc']])->values();
    }

    public function summarise(User $user, ?TenantMembership $m, Collection $events, CarbonImmutable $date): array
    {
        $tz = $this->tz();
        $s = $this->settings();
        $isToday = $date->timezone($tz)->isSameDay(now($tz));
        $first = $events->firstWhere('direction', 'in');
        $lastOut = $events->where('direction', 'out')->last();
        $last = $events->last();

        $worked = 0;
        $openIn = null;
        foreach ($events as $e) {
            if ($e->direction === 'in') {
                $openIn ??= $e->occurred_at;
            } elseif ($openIn) {
                $worked += $openIn->diffInMinutes($e->occurred_at, true);
                $openIn = null;
            }
        }
        if ($openIn && $isToday) {
            $worked += $openIn->diffInMinutes(now(), true);
        }

        $late = null;
        if ($first) {
            [$h, $mi] = array_map('intval', explode(':', $s['start_time']) + [0, 0]);
            $due = $date->timezone($tz)->setTime($h, $mi)->addMinutes($s['grace_minutes']);
            $mins = (int) floor($due->diffInMinutes($first->occurred_at->copy()->timezone($tz), false));
            $late = $mins > 0 ? $mins : null;
        }
        $status = ! $last ? 'absent' : ($last->direction === 'in' ? 'in' : 'out');

        return [
            'user_id' => $user->id, 'name' => $user->name, 'avatar_url' => $user->avatar_url, 'job_title' => $m?->job_title,
            'status' => $status, 'status_order' => ['in' => 0, 'out' => 1, 'absent' => 2][$status],
            'first_in' => $first?->occurred_at->copy()->timezone($tz), 'last_out' => $lastOut?->occurred_at->copy()->timezone($tz),
            'worked_minutes' => (int) round($worked), 'late_minutes' => $late,
            'branch' => $last?->branch?->name,
            'events' => $events->map(fn ($e) => [
                'id' => $e->public_id, 'direction' => $e->direction, 'at' => $e->occurred_at->copy()->timezone($tz),
                'method' => $e->method, 'note' => $e->note, 'branch' => $e->branch?->name,
            ])->values()->all(),
        ];
    }

    /** One person's days in a range, newest first. */
    public function history(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $tz = $this->tz();
        $events = AttendanceEvent::with('branch:id,name')->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$from->timezone($tz)->startOfDay()->utc(), $to->timezone($tz)->endOfDay()->utc()])
            ->orderBy('occurred_at')->get()
            ->groupBy(fn ($e) => $e->occurred_at->copy()->timezone($tz)->toDateString());
        $m = TenantMembership::active()->where('user_id', $user->id)->first();

        return $events->map(fn ($evs, $day) => ['date' => $day] + $this->summarise($user, $m, $evs, CarbonImmutable::parse($day, $tz)))
            ->sortKeysDesc()->values();
    }
}
