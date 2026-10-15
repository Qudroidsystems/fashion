<?php

namespace App\Services\Inventory;

use App\Models\User;

/**
 * Separation of duties (SRS §9): above the house limit, the person who
 * submitted a stock adjustment or purchase order should not be the only
 * approver. A house where nobody else holds the approval permission
 * (e.g. a one-person atelier) may still self-approve — the audit trail
 * records that it was self-approved.
 */
class Approvals
{
    public static function otherApproverExists(User $user, string $permission): bool
    {
        return User::inCurrentTenant()->where('users.id', '!=', $user->id)->get()
            ->contains(fn (User $u) => $u->can($permission));
    }

    /** May $approver approve something $submitterId submitted, worth $value against $limit? */
    public static function allowed(User $approver, ?int $submitterId, float $value, float $limit, string $permission): bool
    {
        if (abs($value) <= $limit || $submitterId !== $approver->id) {
            return true;
        }

        return ! self::otherApproverExists($approver, $permission);
    }
}
