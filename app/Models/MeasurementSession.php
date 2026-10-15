<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated set of measurements for a customer, against one template version
 * (SRS FR-MEA-007/008/009). Approved sessions are immutable; corrections
 * create a new session that supersedes the old one.
 */
class MeasurementSession extends Model
{
    use BelongsToTenant, HasUlids;

    public const METHODS = [
        'staff_in_person' => 'Taken by staff',
        'customer_link'   => 'Sent by customer (link)',
        'customer_app'    => 'Sent by customer (app)',
        'imported'        => 'Imported',
    ];

    protected $fillable = [
        'tenant_id', 'customer_id', 'template_version_id', 'branch_id', 'status', 'capture_method', 'display_unit',
        'measured_at', 'measured_by', 'submitted_at', 'verified_by', 'verified_at', 'rejection_reason',
        'supersedes_id', 'correction_reason', 'notes', 'change_summary',
    ];

    protected $casts = [
        'measured_at'    => 'datetime',
        'submitted_at'   => 'datetime',
        'verified_at'    => 'datetime',
        'change_summary' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        // FR-MEA-008: approved sessions cannot be edited (only superseded / status-linked fields).
        static::updating(function (MeasurementSession $s) {
            if ($s->getOriginal('status') === 'approved') {
                $allowed = ['status', 'updated_at'];
                $changed = array_keys($s->getDirty());
                if (array_diff($changed, $allowed) || ($s->isDirty('status') && $s->status !== 'superseded')) {
                    throw new \LogicException('Approved measurement sessions are read-only. Create a correction instead.');
                }
            }
        });
        static::deleting(function (MeasurementSession $s) {
            if (in_array($s->status, ['approved', 'superseded'], true)) {
                throw new \LogicException('Approved measurement sessions cannot be deleted.');
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplateVersion::class, 'template_version_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MeasurementValue::class, 'session_id');
    }

    public function measurer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function getStatusPillAttribute(): string
    {
        return [
            'draft' => 'st-muted', 'submitted' => 'st-warning', 'approved' => 'st-success',
            'rejected' => 'st-danger', 'superseded' => 'st-violet',
        ][$this->status] ?? 'st-muted';
    }
}
