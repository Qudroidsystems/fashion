<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Which parameters a garment/style needs — SRS FR-MEA-004/005. */
class MeasurementTemplate extends Model
{
    use BelongsToTenant;

    public const AUDIENCES = ['any' => 'Anyone', 'female' => 'Women', 'male' => 'Men', 'child' => 'Children'];

    protected $fillable = ['tenant_id', 'name', 'code', 'garment_category', 'audience', 'description', 'is_active', 'current_version_id', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function versions(): HasMany
    {
        return $this->hasMany(MeasurementTemplateVersion::class, 'template_id')->orderByDesc('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplateVersion::class, 'current_version_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(MeasurementTemplateVersion::class, 'template_id')->where('status', 'draft');
    }

    public function scopeUsable($q)
    {
        return $q->where('is_active', true)->whereNotNull('current_version_id');
    }
}
