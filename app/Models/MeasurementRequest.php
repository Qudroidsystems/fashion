<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A secure link asking a customer to send their own measurements (SRS §5.5). */
class MeasurementRequest extends Model
{
    use BelongsToTenant, HasUlids;

    public const MAX_FAILED_ATTEMPTS = 10;

    protected $fillable = [
        'tenant_id', 'customer_id', 'template_version_id', 'token_hash', 'status', 'message', 'expires_at',
        'deadline', 'failed_attempts', 'opened_at', 'submitted_at', 'session_id', 'created_by', 'revoked_by',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at'   => 'datetime',
        'deadline'     => 'date',
        'opened_at'    => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplateVersion::class, 'template_version_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(MeasurementSession::class, 'session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture() && $this->failed_attempts < self::MAX_FAILED_ATTEMPTS;
    }

    public function effectiveStatus(): string
    {
        if ($this->status !== 'pending') {
            return $this->status;
        }
        if ($this->expires_at->isPast()) {
            return 'expired';
        }

        return $this->failed_attempts >= self::MAX_FAILED_ATTEMPTS ? 'locked' : 'pending';
    }
}
