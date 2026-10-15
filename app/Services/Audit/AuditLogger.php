<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Writes audit records for privileged actions (SRS FR-IAM-003 / FR-IAM-005):
 * role changes, user changes, settings, and — as modules arrive — refunds,
 * voids, price overrides, stock adjustments and measurement approvals.
 *
 *   AuditLogger::log('user.updated', $user, $before, $after);
 *   AuditLogger::log('auth.login', $user, description: 'Signed in');
 */
class AuditLogger
{
    /** Keys never written to the audit trail. */
    protected const SECRET_KEYS = [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'token', 'secret', 'api_key', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    public static function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $description = null,
        ?int $tenantId = null,
        ?int $userId = null,
    ): ?AuditLog {
        try {
            $request = request();

            return AuditLog::withoutTenantScope()->create([
                'tenant_id'      => $tenantId ?? CurrentTenant::id(),
                'user_id'        => $userId ?? auth()->id(),
                'action'         => $action,
                'auditable_type' => $subject ? $subject->getMorphClass() : null,
                'auditable_id'   => $subject?->getKey(),
                'description'    => $description ? mb_substr($description, 0, 255) : null,
                'before'         => static::clean($before),
                'after'          => static::clean($after),
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'correlation_id' => $request?->attributes->get('correlation_id'),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the business action, but it must be visible.
            Log::error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Only the attributes that changed, before and after. */
    public static function diff(array $before, array $after): array
    {
        $changed = array_keys(array_filter(
            $after,
            fn ($value, $key) => ! array_key_exists($key, $before) || $before[$key] != $value,
            ARRAY_FILTER_USE_BOTH
        ));

        return [Arr::only($before, $changed), Arr::only($after, $changed)];
    }

    protected static function clean(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return Arr::except($data, static::SECRET_KEYS);
    }
}
