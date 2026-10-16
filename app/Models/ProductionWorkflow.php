<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A template of production stages for a kind of garment (SRS FR-PRD-001). */
class ProductionWorkflow extends Model
{
    use BelongsToTenant;

    public const LINE_KINDS = ['bespoke' => 'Bespoke garments', 'service' => 'Services & alterations', 'ready_made' => 'Ready-made items'];

    protected $fillable = ['tenant_id', 'name', 'description', 'line_kinds', 'category_id', 'is_default', 'is_active'];

    protected $casts = ['line_kinds' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean'];

    public function stages(): HasMany
    {
        return $this->hasMany(ProductionStage::class, 'workflow_id')->orderBy('position')->orderBy('id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogueCategory::class, 'category_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function totalHours(): int
    {
        return (int) $this->stages->sum('duration_hours');
    }
}
