<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * A wedding, event, uniform or other bulk engagement (SRS §5.10).
 * Garments and money live on the linked orders; see ProjectService::summary().
 */
class Project extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = [
        'wedding' => 'Wedding', 'event' => 'Event / party', 'uniform' => 'Uniforms', 'corporate' => 'Corporate order',
        'group' => 'Group / family', 'other' => 'Other',
    ];

    public const STATUSES = [
        'planning'  => ['Planning', 'st-info'],
        'active'    => ['In progress', 'st-violet'],
        'completed' => ['Completed', 'st-success'],
        'cancelled' => ['Cancelled', 'st-danger'],
    ];

    protected $fillable = [
        'tenant_id', 'number', 'name', 'type', 'customer_id', 'manager_id', 'branch_id', 'status', 'event_date', 'delivery_date',
        'venue', 'contract_amount', 'deposit_amount', 'description', 'client_message', 'internal_notes', 'tracking_token_hash',
        'tracking_token', 'tracking_expires_at', 'tracking_viewed_at', 'confirmed_at', 'completed_at', 'cancelled_at',
        'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'event_date' => 'date', 'delivery_date' => 'date', 'contract_amount' => 'float', 'deposit_amount' => 'float',
        'tracking_expires_at' => 'datetime', 'tracking_viewed_at' => 'datetime', 'confirmed_at' => 'datetime',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    protected $hidden = ['tracking_token', 'tracking_token_hash'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ProjectParticipant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class)->latest('id');
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(ProjectBudgetLine::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class)->latest('spent_on')->latest('id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProjectPaymentSchedule::class)->orderBy('due_date')->orderBy('sort_order');
    }

    /** The order billed to the client (one per project; created on first garment). */
    public function mainOrder(): ?Order
    {
        return $this->orders()->where('customer_id', $this->customer_id)->whereNot('status', 'cancelled')->oldest('id')->first();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['planning', 'active'], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The plain tracking token (stored encrypted so staff can share it again). */
    public function trackingToken(): ?string
    {
        try {
            return $this->tracking_token ? Crypt::decryptString($this->tracking_token) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function trackingOpen(): bool
    {
        return (bool) $this->tracking_token_hash && (! $this->tracking_expires_at || $this->tracking_expires_at->isFuture())
            && $this->status !== 'cancelled';
    }

    /** Projects this user may open (SRS §9: managers all; others the ones they run or work on). */
    public function scopeVisibleTo($q, User $user)
    {
        if ($user->can('Manage project') || $user->can('View financial reports') || $user->can('Issue material')) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('manager_id', $user->id)
            ->orWhereHas('orders.tasks', fn ($t) => $t->where('assigned_to', $user->id)));
    }
}
