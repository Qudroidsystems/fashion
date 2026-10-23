<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One QR scan (or manual correction) of a staff member coming in or going out. Never edited. */
class AttendanceEvent extends Model
{
    use BelongsToTenant, HasPublicId;

    public const METHODS = ['qr_rotating' => 'Entrance screen QR', 'qr_static' => 'Printed QR', 'manual' => 'Added by a manager'];

    protected $fillable = [
        'tenant_id', 'user_id', 'branch_id', 'direction', 'occurred_at', 'method', 'recorded_by', 'note', 'device', 'ip', 'latitude', 'longitude',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Attendance records are not edited. Add a correction instead.'));
        static::deleting(fn () => throw new \LogicException('Attendance records are not deleted. Add a correction instead.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
