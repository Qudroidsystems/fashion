<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Configurable quality checklist (SRS FR-PRD-006). Items: [{label, required}]. */
class QualityChecklist extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'items', 'is_active'];

    protected $casts = ['items' => 'array', 'is_active' => 'boolean'];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
