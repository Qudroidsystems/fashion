<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A shop, workshop, warehouse or POS location of a fashion house (SRS FR-TEN-004). */
class Branch extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'branch'     => 'Branch / shop',
        'production' => 'Production room',
        'warehouse'  => 'Warehouse / store',
        'pos'        => 'POS point',
    ];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'type', 'phone', 'email', 'address', 'city', 'state',
        'is_main', 'is_active', 'opening_hours', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_main'       => 'boolean',
        'is_active'     => 'boolean',
        'opening_hours' => 'array',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
