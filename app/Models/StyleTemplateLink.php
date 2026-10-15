<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which measurement template(s) a style version needs; the template version is pinned on publish. */
class StyleTemplateLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'style_version_id', 'template_id', 'template_version_id', 'sort_order'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplate::class, 'template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(MeasurementTemplateVersion::class, 'template_version_id');
    }
}
