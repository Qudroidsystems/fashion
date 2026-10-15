<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A numbered version of a template. Published versions are immutable (FR-MEA-005). */
class MeasurementTemplateVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'template_id', 'version_no', 'status', 'change_notes', 'published_at', 'published_by'];

    protected $casts = ['published_at' => 'datetime'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplate::class, 'template_id');
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(MeasurementTemplateParameter::class, 'template_version_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function getLabelAttribute(): string
    {
        return ($this->template?->name ?? 'Template') . ' v' . $this->version_no;
    }
}
